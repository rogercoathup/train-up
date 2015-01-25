<?php

/**
 * The admin screen for working with Tests
 *
 * @package Train-Up!
 * @subpackage Tests
 */

namespace TU;

class Test_admin extends Post_admin {

  /**
   * __construct
   *
   * - Creates a new admin section for managing Tests
   * - Load the test post type, refresh it to get the latest info.
   * - Carry on constructing the post type admin as normal,
   *   then if active, add Test specific actions.
   * - If viewing the list of Tests, apply the filters
   *
   * @access public
   */
  public function __construct() {
    $post_type = new Test_post_type;
    $post_type->refresh();

    parent::__construct($post_type);

    if ($this->is_active()) {
      $this->pre_process();

      add_action('admin_enqueue_scripts', array($this, '_add_assets'));
      add_action('default_content', array($this, '_default_content'));
      add_action('admin_footer', array($this, '_render_importer'));
      add_action('post_submitbox_misc_actions', array($this, '_publish_meta'));

      if ($this->is_browsing()) {
        add_action('pre_get_posts', array(__NAMESPACE__.'\\Tests', '_filter'));
      }
    }
  }

  /**
   * _add_assets
   *
   * - Fired on `admin_enqueue_scripts` when Test administration is active
   * - Enqueue the necessary scripts and styles
   *
   * @access private
   */
  public function _add_assets() {
    wp_enqueue_script('tu_tests');
    wp_enqueue_style('tu_tests');
    wp_enqueue_script('tu_grades');
    wp_enqueue_style('tu_grades');
    wp_enqueue_script('tu_importer');
    wp_enqueue_style('tu_importer');
  }

  /**
   * get_meta_boxes
   *
   * @access protected
   *
   * @return array A hash of meta boxes to show when dealing with Tests.
   */
  protected function get_meta_boxes() {
    $meta_boxes = array(
      'shortcodes' => array(
        'title'    => __('Shortcodes', 'trainup'),
        'context'  => 'side',
        'priority' => 'high',
        'closed'   => true
      ),
      'options' => array(
        'title'    => __('Options', 'trainup'),
        'context'  => 'side',
        'priority' => 'high'
      ),
      'questions' => array(
        'title'    => __('Questions', 'trainup'),
        'context'  => 'advanced',
        'priority' => 'default'
      ),
      'test_grades' => array(
        'title'    => sprintf(__('%1$s grades', 'trainup'), tu()->config['tests']['single']),
        'context'  => 'side',
        'priority' => 'low'
      )
    );

    if ($this->is_editing()) {
      $meta_boxes['relationships'] = array(
        'title'    => __('Relationships', 'trainup'),
        'context'  => 'side',
        'priority' => 'default'
      );
    }

    return $meta_boxes;
  }

  /**
   * get_columns
   *
   * Returns a hash of extra columns to include when displaying Tests in the
   * backend. Each key gets automatically mapped to a function.
   *
   * @access protected
   *
   * @return array
   */
  protected function get_columns() {
    return parent::get_columns() + array(
      'level'   => tu()->config['levels']['single'],
      'results' => __('Results', 'trainup'),
      'archive' => __('Archive', 'trainup')
    );
  }

  /**
   * meta_box_options
   *
   * - Fired when the 'options' meta box is to be rendered.
   *   (It displays options like the number of available resits for the test).
   * - Echo out the view
   *
   * @access protected
   */
  protected function meta_box_options() {
    $settings   = Settings::get_structure();
    $post_stati = $settings['tests']['settings']['default_result_status']['options'];

    echo new View(tu()->get_path('/view/backend/tests/options_meta'), array(
      'test'          => tu()->test,
      'levels'        => Levels::find_all(array('post_status' => 'any')),
      'current_level' => tu()->test->level,
      'post_stati'    => $post_stati,
      '_level'        => strtolower(tu()->config['levels']['single']),
      '_test'         => strtolower(tu()->config['tests']['single'])
    ));
  }

  /**
   * meta_box_relationships
   *
   * - Fired when the 'relationships' meta box is to be rendered.
   *   (It displays links to posts that are associated with the active Test).
   * - Echo out the view
   *
   * @access protected
   */
  protected function meta_box_relationships() {
    echo new View(tu()->get_path('/view/backend/tests/relation_meta'), array(
      'test'      => tu()->test,
      'level'     => tu()->test->level,
      '_level'    => strtolower(tu()->config['levels']['single']),
      '_test'     => strtolower(tu()->config['tests']['single']),
      '_trainees' => strtolower(tu()->config['trainees']['plural'])
    ));
  }

  /**
   * meta_box_questions
   *
   * - Fired when the 'questions' meta box is to be rendered.
   *   (It displays all the question posts that belong to the active Test, note
   *   this is an alternative to using the default UI generated by WordPress
   *   for displaying posts. We felt it would be too confusing to have another
   *   depth to the breadcrumb trail)
   * - Echo out the view
   *
   * @access protected
   */
  protected function meta_box_questions() {
    echo new View(tu()->get_path('/view/backend/tests/questions_meta'), array(
      'is_editing' => $this->is_editing(),
      'can_edit'   => tu()->test->can_edit(),
      'test_id'    => tu()->test->ID,
      'questions'  => tu()->test->get_questions(array('post_status' => 'any')),
      '_trainees'  => strtolower(tu()->config['trainees']['plural']),
      '_test'      => strtolower(tu()->config['tests']['single'])
    ));
  }

  /**
   * meta_box_test_grades
   *
   * - Fired when the 'grades' meta box is to be rendered.
   *   (It displays a form that lets administrators choose specific grade
   *   information, for the active test - stuff like minimum pass percentage).
   * - Echo out the view
   *
   * @access protected
   */
  protected function meta_box_test_grades() {
    echo new View(tu()->get_path('/view/backend/forms/grade_selector'), array(
      'grades'   => tu()->test->grades,
      'small'    => true,
      'disabled' => $this->is_editing()
    ));
  }

  /**
   * column_level
   *
   * - Callback for the 'level' column
   * - Get the ID of the level associated with the active test and output a
   *   link to edit it.
   *
   * @access protected
   */
  protected function column_level() {
    global $post;
    $level_id = Tests::factory($post)->get_level_id();

    if ($level_id) {
      $href = "post.php?post={$level_id}&action=edit";
      $text = sprintf(__('Edit %1$s', 'trainup'), strtolower(tu()->config['levels']['single']));

      echo "<a href='{$href}'>{$text}&nbsp;&raquo;</a>";
    } else {
      echo '&ndash;';
    }
  }

  /**
   * column_results
   *
   * - Callback for the 'results' column
   * - If the active test has been started, the provide a link to view the
   *   result posts that have been generated for it i.e. Trainee test attempts.
   *
   * @access protected
   */
  protected function column_results() {
    global $post;
    $test = Tests::factory($post);

    if ($test->started()) {
      $href = "edit.php?post_type=tu_result_{$test->ID}";
      $text = __('View results', 'trainup');

      echo "<a href='{$href}'>{$text}&nbsp;&raquo;</a>";
    } else {
      echo '&ndash;';
    }
  }

  /**
   * column_archive
   *
   * - Callback for the 'archive' column
   * - If the active test has been started, output a link to view the archived
   *   result information.
   *
   * @access protected
   */
  protected function column_archive() {
    global $post;
    $test = Tests::factory($post);

    if ($test->started()) {
      $href = "admin.php?page=tu_results&tu_test_id={$test->ID}";
      $text = __('View archive', 'trainup');

      echo "<a href='{$href}'>{$text}&nbsp;&raquo;</a>";
    } else {
      echo '&ndash;';
    }
  }

  /**
   * on_save
   *
   * - Fired when a Test is saved
   * - Set the ID of the Level that this Test belongs to, bail if no level
   *   is provided
   * - Set the amount of resits the test can have
   * - Set any custom grade information for the test
   * - Set the time limit for the test
   * - Set the default Result post status for when Trainees complete the Test.
   *
   * @param integer $post_id
   * @param object $post
   *
   * @access protected
   */
  protected function on_save($post_id, $post) {
    $test = Tests::factory($post);

    if (!empty($_POST['tu_level_id'])) {
      $test->set_level_id($_POST['tu_level_id']);
    } else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      wp_die(sprintf(
        __('No %1$s specified', 'trainup'),
        strtolower(tu()->config['levels']['single'])
      ));
    }

    if (isset($_POST['tu_resit_attempts'])) {
      $test->set_resit_attempts($_POST['tu_resit_attempts']);
    }

    if (isset($_POST['tu_time_limit'])) {
      $test->set_time_limit($_POST['tu_time_limit']);
    }

    if (isset($_POST['tu_result_status'])) {
      $test->set_result_status($_POST['tu_result_status']);
    }

    if (isset($_POST['tu_settings_tests']) && isset($_POST['tu_settings_tests']['grades'])) {
      $default_grades = tu()->config['tests']['grades'];
      $custom_grades  = $_POST['tu_settings_tests']['grades'];

      if ($default_grades != $custom_grades) {
        $test->set_grades($custom_grades);
      }
    }

    $test->save();
  }

  /**
   * on_delete
   *
   * - Fired when a test is about to be deleted
   * - Load the test and delete it, but pass in false which prevents the post
   *   from actually being deleted, because WordPress is about to do that for us
   *
   * @param integer $post_id
   *
   * @access protected
   */
  protected function on_delete($post_id) {
    Tests::factory($post_id)->delete(false);
  }

  /**
   * on_trash
   *
   * - Fired when a test is about to be trashed
   * - Load the test and trash it, but pass in false which prevents the post
   *   from actually being trashed, because WordPress is about to do that for us
   *
   * @param integer $post_id
   *
   * @access protected
   */
  protected function on_trash($post_id) {
    Tests::factory($post_id)->trash(false);
  }

  /**
   * on_untrash
   *
   * - Fired when a test is about to untrashed
   * - Load the test and untrash it, but pass in false which prevents the post
   *   from actually being untrashed, because WordPress is about to do that.
   *
   * @param integer $post_id
   *
   * @access protected
   */
  protected function on_untrash($post_id) {
    Tests::factory($post_id)->untrash(false);
  }

  /**
   * pre_process
   *
   * - Listen out for requests to delete a question
   * - Listen out for requests to reset a test.
   * - Set a flash message to let the user know the outcome of the requests.
   *
   * @access private
   */
  private function pre_process() {
    $_test = tu()->config['tests']['single'];

    if (isset($_GET['tu_remove_question'])) {
      Questions::factory($_GET['tu_remove_question'])->delete();
      tu()->message->set_flash('success', __('Question deleted', 'trainup'));
    }

    if (isset($_GET['tu_reset_test'])) {
      Tests::factory($_GET['tu_reset_test'])->reset();
      tu()->message->set_flash('success', sprintf(__('%1$s reset', 'trainup'), $_test));
    }
  }

  /**
   * _default_content
   *
   * - Fired on `default_content`
   * - Populate the WYWISYG editor with the default content for tests.
   *
   * @param string $content
   *
   * @access private
   *
   * @return string The altered content
   */
  public function _default_content($content) {
    return tu()->config['tests']['default_content'];
  }

  /**
   * _render_importer
   *
   * - Fired on `admin_footer`
   * - Prints out the admin interface for importing Questions into a Test
   *   but only when on the add/edit screen.
   *
   * @access private
   */
  public function _render_importer() {
    if ( !($this->is_adding() || $this->is_editing()) ) return;

    echo new View(tu()->get_path('/view/backend/importer/index'), array(
      '_test'   => tu()->config['tests']['single'],
      'test_id' => tu()->test->ID
    ));
  }

  /**
   * _publish_meta
   *
   * - Fired on `post_submitbox_misc_actions` when a test is active
   *   (inside the Publish meta box)
   * - Render the option to Reset a Test.
   *
   * @access private
   */
  public function _publish_meta() {
    echo new View(tu()->get_path('/view/backend/tests/publish_meta'), array(
      'test_id' => tu()->test->ID,
      '_test'   => strtolower(tu()->config['tests']['single'])
    ));
  }

}



