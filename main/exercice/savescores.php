<?php
/* For licensing terms, see /license.txt */
/**
 * 	Saving the scores.
 * 	@package chamilo.exercise
 * 	@author
 * 	@version $Id: savescores.php 15602 2008-06-18 08:52:24Z pcool $
 */
require_once '../inc/global.inc.php';
$courseInfo = api_get_course_info();
$_user = api_get_user_info();

$this_section = SECTION_COURSES;
$documentPath = api_get_path(SYS_COURSE_PATH).$courseInfo['path']."/document";

$test = $_REQUEST['test'];
$full_file_path = $documentPath.$test;

my_delete($full_file_path.$_user['user_id'].".t.html");

$TABLETRACK_HOTPOTATOES = Database::get_main_table(TABLE_STATISTIC_TRACK_E_HOTPOTATOES);
$TABLE_LP_ITEM_VIEW = Database::get_course_table(TABLE_LP_ITEM_VIEW);

$score = $_REQUEST['score'];
$origin = $_REQUEST['origin'];
$learnpath_item_id = intval($_REQUEST['learnpath_item_id']);
$lpViewId = isset($_REQUEST['lp_view_id']) ? intval($_REQUEST['lp_view_id']) : null;
$course_id = $courseInfo['real_id'];
$jscript2run = '';

/**
 * Save the score for a HP quiz. Can be used by the learnpath tool as well
 * for HotPotatoes quizzes. When coming from the learning path, we
 * use the session variables telling us which item of the learning path has to
 * be updated (score-wise)
 * @param	string	File is the exercise name (the file name for a HP)
 * @param	integer	Score to save inside the tracking tables (HP and learnpath)
 * @return	void
 */
function save_scores($file, $score)
{
    global $origin, $_user, $TABLETRACK_HOTPOTATOES;
    // if tracking is disabled record nothing
    $weighting = 100; // 100%
    $date = api_get_utc_datetime();
    $c_id = api_get_course_int_id();

    if ($_user['user_id']) {
        $user_id = $_user['user_id'];
    } else {
        // anonymous
        $user_id = "NULL";
    }
    $sql = "INSERT INTO $TABLETRACK_HOTPOTATOES (exe_name, exe_user_id, exe_date, c_id, exe_result, exe_weighting) VALUES (
			'".Database::escape_string($file)."',
			".intval($user_id).",
			'".Database::escape_string($date)."',
			$c_id,
			'".Database::escape_string($score)."',
			'".Database::escape_string($weighting)."')";

    Database::query($sql);

    if ($origin == 'learnpath') {
        //if we are in a learning path, save the score in the corresponding
        //table to get tracking in there as well
        global $jscript2run;
        //record the results in the learning path, using the SCORM interface (API)
        $jscript2run .= "<script>
            $(document).ready(function() {
                //API_obj = window.frames.window.content.API;
                //API_obj = $('content_id').context.defaultView.content.API; //works only in FF
                //API_obj = window.parent.frames.window.top.API;
                API_obj = window.top.API;
                API_obj.void_save_asset('$score', '$weighting', 0, 'completed');
            });
        </script>";
    }
}

// Save the Scores
save_scores($test, $score);

// Back
if ($origin != 'learnpath') {
    $url = "exercise.php"; // back to exercises
    $jscript2run .= '<script>'."window.open('$url', '_top', '')".'</script>';
    echo $jscript2run;
} else {
    $htmlHeadXtra[] = $jscript2run;
    Display::display_reduced_header();
    if (!empty($course_id) && !empty($learnpath_item_id) && !empty($lpViewId)) {
        $sql = "UPDATE $TABLE_LP_ITEM_VIEW SET
                    status = 'completed'
                WHERE
                    c_id = $course_id AND
                    lp_item_id= $learnpath_item_id AND
                    lp_view_id = $lpViewId
                ";
        Database::query($sql);
        Display::display_confirmation_message(get_lang('HotPotatoesFinished'));
    } else {
        Display::display_error_message(get_lang('Error'));
    }

    Display::display_footer();
}
