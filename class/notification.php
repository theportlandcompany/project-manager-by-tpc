<?php

class CPM_Notification {

    function __construct() {
        //notify users
        add_action( 'cpm_project_new', array($this, 'project_new'), 10, 2 );
        add_action( 'cpm_project_change_status', array($this, 'project_complete'), 10, 2 );

        add_action( 'cpm_comment_new', array($this, 'new_comment'), 10, 3 );
        add_action( 'cpm_message_new', array($this, 'new_message'), 10, 2 );
        
        add_action( 'cpm_task_new', array($this, 'new_task'), 10, 3 );
        add_action( 'cpm_task_update', array($this, 'new_task'), 10, 3 );
        add_action( 'cpm_task_complete', array($this, 'complete_task'), 10, 1 );
    }

    function prepare_contacts() {
        $to = array();
        if ( isset( $_POST['notify_user'] ) ) {
            foreach ($_POST['notify_user'] as $user_id) {
                $user_info = get_user_by( 'id', $user_id );
                $to[] = sprintf( '%s<%s>', $user_info->display_name, $user_info->user_email );
            }
        }

        return $to;
    }

    /**
     * Notify users about the new project creation
     *
     * @uses `cpm_new_project` hook
     * @param int $project_id
     */
    function project_new( $project_id, $data ) {

        if ( isset( $_POST['project_notify'] ) && $_POST['project_notify'] == 'yes' ) {
            $co_workers = $_POST['project_coworker'];
            $users = array();

            foreach ($co_workers as $user_id) {
                $user = get_user_by( 'id', $user_id );
                $users[$user_id] = sprintf( '%s <%s>', $user->display_name, $user->user_email );
            }

            //if any users left, get their mail addresses and send mail
            if ( $users ) {

                $site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
                $subject = sprintf( __( 'New Project invitation on %s', 'cpm' ), $site_name );
                $message = sprintf( __( 'You have been added as a Contributor on "%s" on %s', 'cpm' ), trim( $data['post_title'] ), $site_name ) . "\r\n";
                $message .= sprintf( __( '<a href="%s">View Project »</a>', 'cpm' ), cpm_url_project_details( $project_id ) ) . "\r\n";

                $this->send( implode(', ', $users), $subject, $message );
            }
        }
    }

    /**
     * Notify users upon project completion
     *
     * @uses `cpm_project_change_status` hook
     * @param int $project_id
     * @param string $project_status
     */
    function project_complete( $project_id, $project_status ) {
        if ( $project_status != 'complete' ) return;

        $project_obj = CPM_Project::getInstance();
        $co_workers = $project_obj->get_users( $project_id );
        $project = $project_obj->get( $project_id );
        $users = array();

        $current_user_data = get_userdata( get_current_user_id() );

        foreach ($co_workers as $user) {
            if ( $user['id'] == get_current_user_id() )
                continue;

            $users[$user['id']] = sprintf( '%s <%s>', $user['name'], $user['email'] );
        }

        //if any users left, get their mail addresses and send mail
        if ( $users ) {

            $site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
            $subject = sprintf( __( '"%s" Project has been marked completed', 'cpm' ), $project->post_title );
            $message .= sprintf( __( 'The Project "<a href="%s">"%s"</a>" was marked as complete by %s.', 'cpm' ), cpm_url_project_details( $project_id ), $project->post_title, $current_user_data->display_name );

            $this->send( implode(', ', $users), $subject, $message );
        }
    }

    function new_message( $message_id, $project_id ) {
        $users = $this->prepare_contacts();

        if ( !$users ) {
            return;
        }

        $pro_obj = CPM_Project::getInstance();
        $msg_obj = CPM_Message::getInstance();

        $project = $pro_obj->get( $project_id );
        $msg = $msg_obj->get( $message_id );
        $author = wp_get_current_user();

        $subject = sprintf( __( '[%s] New message on project: %s', 'cpm' ), __( 'Project Manager', 'cpm' ), $project->post_title );
        $message = sprintf( 'New message on %s', $project->post_title ) . "\r\n\n";
        $message .= sprintf( 'Author : %s', $author->display_name ) . "\r\n";
        $message .= sprintf( __( 'Permalink : %s' ), cpm_url_single_message( $project_id, $message_id ) ) . "\r\n";
        $message .= sprintf( "Message : \r\n%s", $msg->post_content ) . "\r\n";

        $users = apply_filters( 'cpm_new_message_to', $users );
        $subject = apply_filters( 'cpm_new_message_subject', $subject );
        $message = apply_filters( 'cpm_new_message_message', $message );

        $this->send( implode( ', ', $users ), $subject, $message );
    }

    /**
     * Send email to all about a new comment
     *
     * @param int $comment_id
     * @param array $comment_info the post data
     */
    function new_comment( $comment_id, $project_id, $data ) {
        $users = $this->prepare_contacts();

        if ( !$users ) {
            return;
        }

        $msg_obj = CPM_Message::getInstance();
        $parent_post = get_post( $data['comment_post_ID'] );
        $post_type = get_post_type_object( $parent_post->post_type );
        $author = wp_get_current_user();

        $subject = sprintf( __( 'New comment on %s "%s"', 'cpm' ), strtolower( $post_type->labels->singular_name ), $parent_post->post_title );
        $message .= sprintf( '<p>%s said:</p>', $author->display_name );
        $message .= sprintf( '<p><a href="%s">%s</a></p>', cpm_url_single_message( $project_id, $data['comment_post_ID'] ), $data['comment_content'] );

        $users = apply_filters( 'cpm_new_comment_to', $users );
        $subject = apply_filters( 'cpm_new_comment_subject', $subject );
        $message = apply_filters( 'cpm_new_comment_message', $message );

        $this->send( implode( ', ', $users ), $subject, $message );
    }

    function new_task( $list_id, $task_id, $data ) {
        $task_obj = CPM_Task::getInstance();
        $task = $task_obj->get_task( $task_id );

        $list_id = get_post_field( 'post_parent', $task_id );
        $project_id = get_post_field( 'post_parent', $list_id );

        //notification is not selected or no one is assigned
        if ( $_POST['task_assign'] == '-1' ) {
            return;
        }

        // if task is assigned to self
        if ( intval( $_POST['task_assign'] ) == get_current_user_id() ) {
            return;
        }

        $user = get_user_by( 'id', intval( $_POST['task_assign'] ) );
        $to = sprintf( '%s <%s>', $user->display_name, $user->user_email );

        $subject = sprintf( __( '%s » %s » New Task Assigned.', 'cpm' ), get_post_field( 'post_title', $project_id ), get_post_field( 'post_title', $list_id ) );
        $message = sprintf( __('Project: <a href="%s">%s</a> » Task List: <a href="%s">%s</a> » Task: <a href="%s">#%s - %s</a>' ), cpm_url_project_details( $project_id ), get_post_field( 'post_title', $project_id ), cpm_url_single_tasklist( $project_id, $list_id ), get_post_field( 'post_title', $list_id ), cpm_url_single_task( $project_id, $list_id, $task_id ), $task_id, get_post_field( 'post_content', $task_id ) . "\r\n\n");

        $this->send( $to, $subject, $message );
    }

     /**
     * Notify users upon task completion
     *
     * @uses `cpm_task_complete` hook
     * @param int $task_id
     */
    function complete_task( $task_id ) {
        $task_obj = CPM_Task::getInstance();
        $task = $task_obj->get_task( $task_id );

        $list_id = get_post_field( 'post_parent', $task_id );
        $project_id = get_post_field( 'post_parent', $list_id );

        $project_obj = CPM_Project::getInstance();
        $co_workers = $project_obj->get_users( $project_id );
        $users = array();

        $current_user_data = get_userdata( get_current_user_id() );

        foreach ($co_workers as $user) {
            if ( $user['id'] == get_current_user_id() )
                continue;

            $users[$user['id']] = sprintf( '%s <%s>', $user['name'], $user['email'] );
        }

        //if any users left, get their mail addresses and send mail
        if ( $users ) {

            $site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
            $subject = sprintf( __( 'Task completed from %s task list on %s project', 'cpm' ), get_post_field( 'post_title', $list_id ), get_post_field( 'post_title', $project_id ) );
            $message .= sprintf( __( '<p>%s completed the following task:</p>', 'cpm' ), $current_user_data->display_name );
            $message .= sprintf( __( '<p><a href="%s">%s</a></p>', 'cpm' ), cpm_url_single_task( $project_id, $list_id , $task_id ), $task->post_content );

            $this->send( implode(', ', $users), $subject, $message );
        }
    }

    function send( $to, $subject, $message ) {

        $blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
        $wp_email = 'no-reply@' . preg_replace( '#^www\.#', '', strtolower( $_SERVER['SERVER_NAME'] ) );
        $from = "From: \"$blogname\" <$wp_email>";
        $headers = "$from\nContent-Type: text/html; charset=\"" . get_option( 'blog_charset' ) . "\"\n";

        wp_mail( $to, $subject, $message, $headers);
    }

}