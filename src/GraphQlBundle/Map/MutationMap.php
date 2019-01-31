<?php
/* For licensing terms, see /license.txt */

namespace Chamilo\GraphQlBundle\Map;

use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\Session;
use Chamilo\GraphQlBundle\Traits\GraphQLTrait;
use Chamilo\UserBundle\Entity\User;
use GraphQL\Type\Definition\ResolveInfo;
use Overblog\GraphQLBundle\Definition\Argument;
use Overblog\GraphQLBundle\Error\UserError;
use Overblog\GraphQLBundle\Resolver\ResolverMap;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;

/**
 * Class MutationMap.
 *
 * @package Chamilo\GraphQlBundle\Map
 */
class MutationMap extends ResolverMap implements ContainerAwareInterface
{
    use GraphQLTrait;

    /**
     * @return array
     */
    protected function map()
    {
        return [
            'Mutation' => [
                self::RESOLVE_FIELD => function ($value, Argument $args, \ArrayObject $context, ResolveInfo $info) {
                    $method = 'resolve'.ucfirst($info->fieldName);

                    return $this->$method($args, $context);
                },
            ],
        ];
    }

    /**
     * @param Argument $args
     *
     * @return array
     */
    protected function resolveAuthenticate(Argument $args)
    {
        /** @var User $user */
        $user = $this->em->getRepository('ChamiloUserBundle:User')->findOneBy(['username' => $args['username']]);

        if (!$user) {
            throw new UserError($this->translator->trans('User not found.'));
        }

        $encoder = $this->container->get('security.password_encoder');
        $isValid = $encoder->isPasswordValid($user, $args['password']);

        if (!$isValid) {
            throw new UserError($this->translator->trans('Password is not valid.'));
        }

        return [
            'urlId' => $this->currentAccessUrl->getId(),
            'token' => $this->encodeToken($user),
        ];
    }

    /**
     * @param Argument $args
     *
     * @return array
     */
    protected function resolveViewerSendMessage(Argument $args)
    {
        $this->checkAuthorization();

        $usersRepo = $this->em->getRepository('ChamiloUserBundle:User');
        $users = $usersRepo->findUsersToSendMessage($this->currentUser->getId());
        $receivers = array_filter(
            $args['receivers'],
            function ($receiverId) use ($users) {
                /** @var User $user */
                foreach ($users as $user) {
                    if ($user->getId() === (int) $receiverId) {
                        return true;
                    }
                }

                return false;
            }
        );

        $result = [];

        foreach ($receivers as $receiverId) {
            $messageId = \MessageManager::send_message(
                $receiverId,
                $args['subject'],
                $args['text'],
                [],
                [],
                0,
                0,
                0,
                0,
                $this->currentUser->getId()
            );

            $result[] = [
                'receiverId' => $receiverId,
                'sent' => (bool) $messageId,
            ];
        }

        return $result;
    }

    /**
     * @param Argument $args
     *
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Doctrine\ORM\TransactionRequiredException
     *
     * @return Course
     */
    protected function resolveCreateCourse(Argument $args): ?Course
    {
        $this->checkAuthorization();

        $checker = $this->container->get('security.authorization_checker');

        if (false === $checker->isGranted('ROLE_ADMIN')) {
            throw new UserError($this->translator->trans('Not allowed'));
        }

        $course = $args['course'];
        $originalCourseIdName = $args['originalCourseIdName'];
        $originalCourseIdValue = $args['originalCourseIdValue'];

        $title = $course['title'];
        $categoryCode = !empty($course['categoryCode']) ? $course['categoryCode'] : null;
        $wantedCode = isset($course['wantedCode']) ? $course['wantedCode'] : null;
        $language = $course['language'];
        $visibility = isset($course['visibility']) ? $course['visibility'] : COURSE_VISIBILITY_OPEN_PLATFORM;
        $diskQuota = $course['diskQuota'] * 1024 * 1024;
        $allowSubscription = $course['allowSubscription'];
        $allowUnsubscription = $course['allowUnsubscription'];

        $courseInfo = \CourseManager::getCourseInfoFromOriginalId($originalCourseIdValue, $originalCourseIdName);

        if (!empty($courseInfo)) {
            throw new UserError($this->translator->trans('Course already exists'));
        }

        $params = [
            'title' => $title,
            'wanted_code' => $wantedCode,
            'category_code' => $categoryCode,
            //'tutor_name',
            'course_language' => $language,
            'user_id' => $this->currentUser->getId(),
            'visibility' => $visibility,
            'disk_quota' => $diskQuota,
            'subscribe' => !empty($allowSubscription),
            'unsubscribe' => !empty($allowUnsubscription),
        ];

        $courseInfo = \CourseManager::create_course(
            $params,
            $this->currentUser->getId(),
            $this->currentAccessUrl->getId()
        );

        if (empty($courseInfo)) {
            throw new UserError($this->translator->trans('Course not created'));
        }

        \CourseManager::create_course_extra_field(
            $originalCourseIdName,
            \ExtraField::FIELD_TYPE_TEXT,
            $originalCourseIdName
        );

        \CourseManager::update_course_extra_field_value(
            $courseInfo['code'],
            $originalCourseIdName,
            $originalCourseIdValue
        );

        return $this->em->find('ChamiloCoreBundle:Course', $courseInfo['real_id']);
    }

    /**
     * @param Argument $args
     *
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Doctrine\ORM\TransactionRequiredException
     *
     * @return User|null
     */
    protected function resolveCreateUser(Argument $args): ?User
    {
        $this->checkAuthorization();

        $checker = $this->container->get('security.authorization_checker');

        if (false === $checker->isGranted('ROLE_ADMIN')) {
            throw new UserError($this->translator->trans('Not allowed'));
        }

        $userInput = $args['user'];
        $originalUserIdName = $args['originalUserIdName'];
        $originalUserIdValue = $args['originalUserIdValue'];

        $userId = \UserManager::get_user_id_from_original_id($originalUserIdValue, $originalUserIdName);

        if (!empty($userId)) {
            throw new UserError($this->translator->trans('User already exists'));
        }

        if (!\UserManager::is_username_available($userInput['username'])) {
            throw new UserError($this->translator->trans('Username already exists'));
        }

        $language = !empty($userInput['language']) ? $userInput['language'] : null;
        $phone = !empty($userInput['phone']) ? $userInput['phone'] : null;
        $expirationDate = !empty($userInput['expirationDate'])
            ? $userInput['expirationDate']->format('Y-m-d h:i:s')
            : null;

        $userId = \UserManager::create_user(
            $userInput['firstname'],
            $userInput['lastname'],
            $userInput['status'],
            $userInput['email'],
            $userInput['username'],
            $userInput['password'],
            null,
            $language,
            $phone,
            null,
            PLATFORM_AUTH_SOURCE,
            $expirationDate,
            $userInput['isActive']
        );

        if (empty($userId)) {
            throw new UserError($this->translator->trans('User not created'));
        }

        \UrlManager::add_user_to_url(
            $userId,
            $this->currentAccessUrl->getId()
        );
        \UserManager::create_extra_field($originalUserIdName, \ExtraField::FIELD_TYPE_TEXT, $originalUserIdName, '');
        \UserManager::update_extra_field_value($userId, $originalUserIdName, $originalUserIdValue);

        return $this->em->find('ChamiloUserBundle:User', $userId);
    }

    /**
     * @param Argument $args
     *
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Doctrine\ORM\TransactionRequiredException
     *
     * @return bool
     */
    protected function resolveSubscribeUserToCourse(Argument $args): bool
    {
        $this->checkAuthorization();

        $checker = $this->container->get('security.authorization_checker');

        if (false === $checker->isGranted('ROLE_ADMIN')) {
            throw new UserError($this->translator->trans('Not allowed'));
        }

        /** @var User $user */
        $user = $this->em->find('ChamiloUserBundle:User', $args['user']);
        /** @var Course $course */
        $course = $this->em->find('ChamiloCoreBundle:Course', $args['course']);

        if (null === $user) {
            throw new UserError($this->translator->trans('User not found'));
        }

        if (null === $course) {
            throw new UserError($this->translator->trans('Course not found'));
        }

        if (!\UrlManager::relation_url_user_exist($user->getId(), $this->currentAccessUrl->getId())) {
            throw new UserError($this->translator->trans('User not registered in this URL'));
        }

        if (!\UrlManager::relation_url_course_exist($course->getId(), $this->currentAccessUrl->getId())) {
            throw new UserError($this->translator->trans('Course not registered in this URL'));
        }

        $isSubscribed = \CourseManager::subscribeUser($user->getId(), $course->getCode());

        return $isSubscribed;
    }

    /**
     * @param Argument $args
     *
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Doctrine\ORM\TransactionRequiredException
     *
     * @return int
     */
    protected function resolveAddForumThread(Argument $args): int
    {
        $this->checkAuthorization();

        require_once api_get_path(SYS_CODE_PATH).'forum/forumfunction.inc.php';

        /** @var Course $course */
        $course = $this->em->find('ChamiloCoreBundle:Course', $args['course']);
        /** @var Session|null $session */
        $session = null;

        if (!$course) {
            throw new UserError($this->translator->trans('Course not found'));
        }

        if (!empty($args['session'])) {
            $session = $this->em->find('ChamiloCoreBundle:Session', $args['session']);

            if (!$session) {
                throw new UserError($this->translator->trans('Session not found'));
            }
        }

        $this->checkCourseAccess($course, $session);

        $forumInfo = get_forums(
            $args['thread']['forum'],
            $course->getCode(),
            true,
            $session ? $session->getId() : 0
        );
        $forumCategoryInfo = get_forumcategory_information($forumInfo['forum_category']);

        if (
            ($forumCategoryInfo['visibility'] && 0 == $forumCategoryInfo) ||
            0 == $forumInfo['visibility']
        ) {
            throw new UserError('Not allowed');
        }

        if (
            ($forumCategoryInfo['visibility'] && 0 != $forumCategoryInfo) ||
            0 != $forumInfo['locked']
        ) {
            throw new UserError('Not allowed');
        }

        if (1 != $forumInfo['allow_new_threads']) {
            throw new UserError('Not allowed');
        }

        if (0 != $forumInfo['forum_of_group']) {
            $showForum = \GroupManager::user_has_access(
                $this->currentUser->getId(),
                $forumInfo['forum_of_group'],
                \GroupManager::GROUP_TOOL_FORUM
            );

            if (!$showForum) {
                throw new UserError('Not allowed');
            }
        }

        $courseInfo = api_get_course_info($course->getCode());

        $threadId = store_thread(
            $forumInfo,
            [
                'post_title' => $args['thread']['title'],
                'forum_id' => $args['thread']['forum'],
                'post_text' => nl2br($args['thread']['text']),
                'post_notification' => $args['notify'],
            ],
            $courseInfo,
            false,
            $this->currentUser->getId(),
            $session ? $session->getId() : 0
        );

        return $threadId;
    }
}
