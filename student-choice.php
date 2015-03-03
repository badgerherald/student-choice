<?php
/*
Plugin Name:    Student Choice
Description:    Poll software to run our student choice awards.
Author:         The Badger Herald
Author URI:     http://badgerherald.com
License:        GNLv2 (or higher)
*/


// This software from 2014, put together quickly.
// Here just as reference.

/**
 * Creates needed tables on plugin activation
 */
function sc_activation() {
    global $wpdb;

    $table_options = $wpdb->prefix.'sc_options';
    $table_participants = $wpdb->prefix.'sc_participants';
    $table_questions = $wpdb->prefix.'sc_questions';
    $table_poll = $wpdb->prefix.'sc_poll';
    $table_votes = $wpdb->prefix.'sc_votes';
    $charset_collate = $wpdb->get_charset_collate();

    $sql1 = "CREATE TABLE $table_options (
            id int(11) NOT NULL AUTO_INCREMENT,
            question_id int(11) NOT NULL,
            option_text text,
            PRIMARY KEY (id),
            KEY question_id (question_id)
    ) $charset_collate;";

    $sql2 = "CREATE TABLE $table_participants (
            id int(11) NOT NULL AUTO_INCREMENT,
            email text NOT NULL,
            poll_name varchar(250) NOT NULL,
            PRIMARY KEY (id),
            KEY poll_name (poll_name)
    ) $charset_collate;";

    $sql3 = "CREATE TABLE $table_questions (
            id int(11) NOT NULL AUTO_INCREMENT,
            poll_name varchar(250) NOT NULL,
            question_name varchar(250) NOT NULL,
            PRIMARY KEY (id),
            KEY poll_name (poll_name)
    ) $charset_collate;";

    $sql4 = "CREATE TABLE $table_poll (
            poll_name varchar(250) NOT NULL,
            PRIMARY KEY (poll_name)
    ) $charset_collate;";
    
    //TODO: add question id to this table
    $sql5 = "CREATE TABLE $table_votes (
            id int(11) NOT NULL AUTO_INCREMENT,
            participant_id int(11) NOT NULL,
            option_id int(11) NOT NULL,
            question_id int(11) NOT NULL,
            PRIMARY KEY (id),
            KEY participant_id (participant_id),
            KEY option_id (option_id),
            KEY question_id (question_id)
    ) $charset_collate;";

    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1);
    dbDelta($sql2);
    dbDelta($sql3);
    dbDelta($sql4);
    dbDelta($sql5);

    $sql = "ALTER TABLE $table_options
            ADD CONSTRAINT {$table_options}_ibfk_1
            FOREIGN KEY (question_id)
            REFERENCES $table_questions (id);";
    $wpdb->query($sql);

    $sql = "ALTER TABLE $table_participants
            ADD CONSTRAINT {$table_participants}_ibfk_1
            FOREIGN KEY (poll_name)
            REFERENCES $table_poll (poll_name);";
    $wpdb->query($sql);

    $sql = "ALTER TABLE $table_questions
            ADD CONSTRAINT {$table_questions}_ibfk_1
            FOREIGN KEY (poll_name)
            REFERENCES $table_poll (poll_name);";
    $wpdb->query($sql);

    $sql = "ALTER TABLE $table_votes
            ADD CONSTRAINT {$table_votes}_ibfk_1
            FOREIGN KEY (participant_id)
            REFERENCES $table_participants (id),
            ADD CONSTRAINT {$table_votes}_ibfk_2
            FOREIGN KEY (option_id)
            REFERENCES $table_options (id),
            ADD CONSTRAINT {$table_votes}_ibfk_3
            FOREIGN KEY (question_id)
            REFERENCES $table_questions (id);";
    $wpdb->query($sql);


}
register_activation_hook( __FILE__, 'sc_activation');

class StudentChoicePoll
{
    public $display_form;
    public $valid_form;
    public $poll_name;
    public $poll_questions;
    public function __construct()
    {
        $this->display_form = true;
        $this->valid_form = true;
        add_action('init', array($this, 'init'));
        add_shortcode('poll', array($this, 'poll_shortcode'));
        add_shortcode('poll-options', array($this, 'poll_options_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_poll_scripts'));
    }

    public function init()
    {
        if (!empty($_POST['sc_poll_form_nonce']))
        {
            if (!wp_verify_nonce($_POST['sc_poll_form_nonce'], 'sc_poll_form'))
            {
                die('Not authorized');
            }
            else
            {
                if (empty($_POST['sc_poll_email']) || !$this->valid_wisc($_POST['sc_poll_email']))
                {
                    $this->valid_form = false;
                }
                else
                {
                    //valid wisc email has been entered, lets start processing the votes.
                    $this->display_form = false;
                    $this->poll_name = $_POST['poll_name'];
                    $email = $_POST['sc_poll_email'];
                    $participant = $this->find_participant($email);
                    $participant_id;
                    if (empty($participant))
                    {
                        $participant_id = $this->create_participant($email);
                    }
                    else
                    {
                        $participant_id = $participant[0]->id;
                    }
                    $this->poll_questions = $this->get_questions($this->poll_name);
                    foreach ($this->poll_questions as $question)
                    {
                        if (isset($_POST['question_'.$question->id]))
                        {
                            $opt_id = $_POST['question_'.$question->id];
                            if (!$this->option_voted_on($participant_id, $question->id))
                            {
                                $this->add_vote($participant_id, $question->id, $opt_id);
                            }
                        }
                    }
                }
            }
        }
    }

    public function enqueue_poll_scripts()
    {
        wp_enqueue_style("sc_poll_style", plugins_url().'/student-choice/sc_style.css');
    }

    function poll_shortcode($atts, $content = '')
    {
        global $wpdb;

        $att = shortcode_atts(array(
            'name' => 'default-poll'
        ), $atts);
        $this->poll_name = $att['name'];
        if (!$this->has_poll($this->poll_name))
        {
            $this->create_poll($this->poll_name);
        }
        $this->poll_questions = $this->get_questions($this->poll_name);
        if ($this->display_form)
        {
            //Form has not been submitted or had errors, lets display the form.
            $ret = '<form action="" method="POST" class="sc_poll">';
            $ret .= '<input type="text" name="poll_name" value="'.$this->poll_name.'" hidden>';
            $ret .= do_shortcode($content);
            $ret .= '<div class="sc_poll_question">';
            $ret .= '<div class="email">';
            $ret .= '<label for="sc_poll_email" class="sc-email-input-label">Please insert your valid @wisc.edu email.</label><br />';
            if (!$this->valid_form)
            {
                $ret .= '<p class="email-err">Please enter a valid @wisc.edu email.</p>';
            }
            $ret .= '<input name="sc_poll_email" id="sc_poll_email" class="sc-email-input" type="text" placeholder="Email">';
            $ret .= '</div>';
            $ret .= wp_nonce_field('sc_poll_form', 'sc_poll_form_nonce');
            $ret .= '<input type="submit" value="Submit" class="sc-submit">';
            $ret .= '</div>';
            $ret .= '</form>';
            return $ret;
        }
        else
        {
            //Form has been submitted and we have returned here from form action.
            $ret = '<h2>Thanks for voting.</h2>';
            return $ret;
        }
    }

    function poll_options_shortcode($atts, $content = '')
    {
        global $wpdb;

        $att = shortcode_atts(array(
            'question' => 'default-question',
            'options' => 'default-options'
        ), $atts);
        $question_slug = strtolower($att['question']);
        $question_slug = str_replace(" ", "-", $question_slug);
        $att['options'] = explode(',', $att['options']);
        $q = $this->has_question($question_slug);
        // TODO: should really factor this out to a function
        if (!$q)
        {
            $this->create_question($question_slug, $att['options']);

            $this->poll_questions = $this->get_questions($this->poll_name);
            $q = $this->has_question($question_slug);
        }
        else
        {
            foreach ($att['options'] as $key => $option)
            {
                $opt_slug = strtolower($option);
                $opt_slug = str_replace(" ", "-", $opt_slug);
                if (!$this->question_has_option($q, $opt_slug))
                {
                    $this->create_option($q->id, $option);
                }
            }

            $this->poll_questions = $this->get_questions($this->poll_name);
            $q = $this->has_question($question_slug);
        }
        $ret = '<div class="sc_poll_question">';
        foreach ($q->options as $key => $option)
        {
            if ($this->bad_option($option, $att['options']))
            {
                continue;
            }

            $checked = '';
            if (isset($_POST['question_'.$q->id])) {
                if ((int)$option->id === (int) $_POST['question_'.$q->id])
                {
                    $checked = ' checked';
                }
            }
            $ret .= '<label class="radio-label" for="sc_poll_'.$q->id.'_'.$option->id.'">';
            $ret .= '<input type="radio" id="sc_poll_'.$q->id.'_'.$option->id.'" name="question_'.$q->id.'" value="'.$option->id.'"'.$checked.'>';
            $ret .= '<span>'.$option->option_text.'</span>';
            $ret .= '</label><br />';
        }
        $ret .= '</div>';
        return $ret;
    }

    function has_poll($poll_name)
    {
        global $wpdb;
        $sql = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}sc_poll
                                WHERE poll_name = %s",
                                $poll_name);
        $result = $wpdb->get_results($sql);

        if (empty($result))
        {
            return false;
        }

        return true;
    }

    function create_poll($poll_name)
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'sc_poll',
            array(
                'poll_name' => $poll_name
            ),
            array(
                '%s'
            )
        );
    }

    function get_questions($poll_name)
    {
        global $wpdb;
        $sql = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}sc_questions
                                WHERE poll_name = %s",
                                $poll_name);
        $result = $wpdb->get_results($sql);

        $questions = array();

        //Could probably be more efficient here with a JOIN but I'm an SQL noob
        foreach ($result as $key => & $val)
        {
            $sql = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}sc_options
                WHERE question_id = %d",
                $val->id
            );
            $opts = $wpdb->get_results($sql);
            $val->options = $opts;
        }

        return $result;
    }

    /**
     * Adds a question and its options to the database
     * @param  string $question_slug question slug
     * @param  array  $options       array of options
     */
    function create_question($question_slug, $options)
    {
        global $wpdb;
        $inserted = $wpdb->insert(
            $wpdb->prefix.'sc_questions',
            array(
                'question_name' => $question_slug,
                'poll_name' => $this->poll_name
            ),
            array(
                '%s',
                '%s'
            )
        );
        if ($inserted)
        {
            $qid = $wpdb->insert_id;
            foreach ($options as $key => $option)
            {
                $this->create_option($qid, $option);
            }
        }
    }

    /**
     * Checks to see if the option is present in the givin list of options
     * @param  object $option      object object from DB
     * @param  array  $option_atts string array from options attribute of shortcode
     * @return bool                false if good option, true otherwise
     */
    function bad_option($option, $option_atts)
    {
        $opt_slug = strtolower($option->option_text);
        $opt_slug = str_replace(" ", "-", $opt_slug);

        foreach ($option_atts as $att)
        {
            $att_slug = strtolower($att);
            $att_slug = str_replace(" ", "-", $att_slug);
            if (strcmp($opt_slug, $att_slug) === 0)
            {
                return false;
            }
        }
        return true;
    }

    function create_option($question_id, $option_text)
    {
        global $wpdb;
        $inserted = $wpdb->insert(
            $wpdb->prefix.'sc_options',
            array(
                'question_id' => $question_id,
                'option_text' => $option_text
            ),
            array(
                '%d',
                '%s'
            )
        );
    }

    function question_has_option($question, $opt_slug)
    {
        foreach ($question->options as $option)
        {
            $curr_slug = strtolower($option->option_text);
            $curr_slug = str_replace(" ", "-", $curr_slug);
            if (strcmp($curr_slug, $opt_slug) === 0)
            {
                return true;
            }
        }
        return false;
    }

    function valid_wisc($email) {
        $email = strtolower($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === FALSE) {
            return FALSE;
        } else {
            if (strpos($email, "wisc.edu") === FALSE) {
                return FALSE;
            }
        }
        return TRUE;
    }

    function has_question($question_slug)
    {
        foreach ($this->poll_questions as $question)
        {
            if (strcmp($question->question_name, $question_slug) === 0) 
            {
                return $question;
            }
        }
        return false;
    }

    function find_participant($email)
    {
        global $wpdb;
        $sql = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}sc_participants
            WHERE email = %s
            AND poll_name = %s",
            $email,
            $this->poll_name
        );
        $result = $wpdb->get_results($sql);
        return $result;
    }

    function create_participant($email)
    {
        global $wpdb;
        $sql = $wpdb->insert($wpdb->prefix.'sc_participants',
            array(
                'email' => $email,
                'poll_name' => $this->poll_name
            ),
            array(
                '%s',
                '%s'
            )
        );
        return $wpdb->insert_id;
    }

    //TODO bit confused why we check if a participant voted on an option and not question
    //Should really check question ID so participants cant double vote
    function option_voted_on($participant_id, $question_id) {
        global $wpdb;
        $sql = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}sc_votes
            WHERE participant_id = %d
            AND question_id = %d",
            $participant_id,
            $question_id
        );
        $result = $wpdb->get_results($sql);
        if (empty($result)) 
        {
            return false;
        } 
        else 
        {
            return true;
        }
    }

    //TODO: Add question id
    function add_vote($participant_id, $question_id, $option_id) {
        global $wpdb;
        if ($option_id === null) {
            return;
        }
        $add_stmt = $wpdb->insert($wpdb->prefix.'sc_votes',
            array(
                'participant_id' => $participant_id,
                'option_id' => $option_id,
                'question_id' => $question_id
            ),
            array(
                '%d',
                '%d',
                '%d'
            )
        );
    }
}

$poll = new StudentChoicePoll();
/*
?>


		<div id="page" class="page-container-fixed-inside">

		<div class="header-sca-2014">
            <a href="http://badgerherald.com"><div class="student-herald-logo">
               
            </div></a>
		</div>

		</div> <!-- #page -->

		<div id="page" class="student-choice-content">
		<div id="wrapper">
		<div id="primary">
		<div id="main" class="site-main">

		<div id="content" class="site-content" role="main">

       

				<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
					<header class="entry-header">
						 <img src="<?php echo bloginfo("template_url"); ?>/img/student-choice/2014/student-choice.png" class="student-logo"/>
					</header><!-- .entry-header -->

					<div class="entry-content">

                        <p class="tagline">Think you know Madison? Tell us what you think is the <strong>best of Madison</strong> and get entered to <strong>win one of 8 $20 Amazon giftcards!</strong></p>
                        <p style="text-align:center;margin-bottom:12px;"><em>Voting ends Tuesday March 18 at midnight.</em></p>

					<?php
                    $display_form = true;
                    $valid = true;
                    $quiz_name = "student-choice-2014";
                    $dbstr = "mysql:host=localhost;dbname=quiz";
                    $username = DB_USER;
                    $password = DB_PASSWORD;
                    $options = array();
                    $dbh = open_db($dbstr, $username, $password, $options);
                    $questions = get_questions($dbh, $quiz_name);
                    if ('POST' == $_SERVER['REQUEST_METHOD']) {
                        if (! array_key_exists("hrld_student_choice_email", $_POST)) {
                            $valid = false;
                        } else {
                            $email = $_POST["hrld_student_choice_email"];
                            if (valid_wisc($email)) {
                                $participant = find_participant($dbh, $email, $quiz_name);
                                if ($participant === NULL) {
                                    $participant = create_participant($dbh, $email, $quiz_name);
                                }
                            } else {
                                // TODO: Show invalid email alert
                                $valid = false;
                            }
                        }
                        if ($valid === true) {
                            $options = array();
                            for ($i = 0; $i < count($questions); $i++) {
                                if (array_key_exists("hrld_student_choice_$i", $_POST)) {
                                    $option_id = $_POST["hrld_student_choice_$i"];
                                } else {
                                    $option_id = NULL;
                                }
                                $options[$i] = $option_id;
                            }
                            
                            try {
                                foreach ($options as $option) {
                                    if (!option_voted_on($dbh, $participant, $option)) {
                                        add_vote($dbh, $participant, $option);
                                    }
                                }
                                $display_form = false;                      
                            } catch (Exception $e) {
                                echo '<p>There was an error!</p>';
                            }
                        }
                    }
                    if(!$valid){
                            ?>
                            <p class="email-err">Please enter a valid @wisc.edu email.</p>
                         <?php  
                        }
					if($display_form):
					?>
						<form action="" method="post" class="quiz-container">
						    <?php       
						    for($i = 0; $i < count($questions); $i++){
                                $current_question = $questions[$i];
                                $question_id = $current_question["id"];
                                $options = get_options($dbh, $question_id);
								echo '<div class="quiz-question clearfix">';
								echo '<div class="question-title"><img src="' . $current_question["photo_url"]  . '"></div>';
								echo '<ul class="answer-list">';                                
                                if(!$valid){
                                    $question_vote = $_POST["hrld_student_choice_$i"];
                                }                                
								for($j = 0; $j < count($options); $j++){
                                    $current_option = $options[$j];
                                    if(isset($question_vote) && $question_vote == $current_option['id']){
                                        $checked  = 'checked="checked"';
                                    }
                                    else{
                                        $checked = '';
                                    }
                                    if(isset($question_vote)){
                                        $inactive = '';
                                    }
                                    else{
                                        $inactive = 'inactive';
                                    }
									echo '<li class="'.$inactive.' answer-box"><input name="hrld_student_choice_'.$i.'" id="hrld_student_choice_'.$i.'_'.$j.'" type="radio" value="' . $current_option['id'] . '" '.$checked.'><label for="hrld_student_choice_'.$i.'_'.$j.'"><img src="' . $current_option["photo_link"] . '" /><span class="answer-description">' . $current_option["text"] . '</span></label></li>';
									if(($j + 1)%3 == 0) echo '</ul><ul class="answer-list">';
								}
								echo '</ul>';
								echo '</div>';
							}
						?>
                        <div class="student-choice-email">
						<label for="hrld_student_choice_email" class="email-input-label">Insert your email. Only valid @wisc.edu emails will be eligible for prizes.</label>
                        <?php
                        if(!$valid){
                            ?>
                            <p class="email-err">Please enter a valid @wisc.edu email.</p>
                            <?php
                        }
                        ?>
						<input name="hrld_student_choice_email" id="hrld_student_choice_email" class="email-input" type="text" placeholder="Email">
						<input type="submit" class="quiz-submit" value="Submit">
                        </div>
                        <p style="opacity:.3;font-size:10px;line-height:9px">No purchase necessary. Only users with valid @wisc.edu will be eligable for prize drawing.</p> 
                        <p style="opacity:.3;font-size:10px;line-height:9px">Photos via flickr users Johm M Quick, Sigamsb, BemLoira BenDevassa, Michael Schoenewies, Brian Miller, Jennifer, danieleloreto, Guillaume Paumier, Jerry Downs, Richard Hurd, Debbie Long, Andypiper, Sam Howzit, Phil Roeder, Dylan_Payne and Pan Pacifi.</p>
						</form>
					<?php
						else:
					?>
						<div class="quiz-success-wrap clearfix">
							<p class="quiz-success">Thank you for voting.</p>

                            <div class="tweet-this" style="background:#fff;width:80%;margin:0 auto;padding:10px;">
                                <h2>Tell your friends!</h2>
                                <p style="color:#000;">I voted in the 2014 @BadgerHerald Student Choice Awards <a href="http://badgerherald.com/student-choice">http://badgerherald.com/student-choice/</a> <a href="https://twitter.com/search?q=%23studentchoice&src=typd&f=realtime">#StudentChoice</a></p>
                                <a href="https://twitter.com/intent/tweet?button_hashtag=StudentChoice&text=I%20voted%20in%20the%202014%20%40BadgerHerald%20Student%20Choice%20Awards!%20" class="twitter-hashtag-button" data-size="large" data-related="badgerherald" data-url="http://badgerherald.com/student-choice/">Tweet #StudentChoice</a>
                                <script>!function(d,s,id){var js,fjs=d.getElementsByTagName(s)[0],p=/^http:/.test(d.location)?'http':'https';if(!d.getElementById(id)){js=d.createElement(s);js.id=id;js.src=p+'://platform.twitter.com/widgets.js';fjs.parentNode.insertBefore(js,fjs);}}(document, 'script', 'twitter-wjs');</script>
                            </div>

							<div class="social-plug">
								<p>Follow us on Twitter and Facebook for contest updates</p>
								<div class="social-buttons">
									<div class="twitter">
										<a href="https://twitter.com/badgerherald" class="twitter-follow-button" data-show-count="false" data-size="large">Follow @badgerherald</a>
										<script>!function(d,s,id){var js,fjs=d.getElementsByTagName(s)[0],p=/^http:/.test(d.location)?'http':'https';if(!d.getElementById(id)){js=d.createElement(s);js.id=id;js.src=p+'://platform.twitter.com/widgets.js';fjs.parentNode.insertBefore(js,fjs);}}(document, 'script', 'twitter-wjs');</script>
									</div><!-- .twitter -->

									<div class="facebook">
										<div class="fb-like" data-href="http://facebook.com/badgerherald" data-layout="button_count" data-action="like" data-show-faces="false" data-share="false" data-height="28" data-width="120"></div>
									</div><!-- .facebook -->
								</div>
                                <div class="clearfix"></div>
                                <p><a href="http://badgerherald.com">Back to badgerherald.com</a></p>
							</div>
						</div>
					<?php
						endif;
					?>
					</div><!-- .entry-content -->
				</article><!-- #post -->

		</div><!-- #content -->
<?php get_footer(); 
*/
