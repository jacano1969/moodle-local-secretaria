<?php

class local_secretaria_exception extends Exception {
    public $errorcode;
}

class local_secretaria_operations {

    function __construct($moodle=null) {
        $this->moodle = $moodle;
    }

    /* Users */

    function get_user($username) {
        if (!$record = $this->moodle->get_user($username)) {
            throw new local_secretaria_exception('Unknown user');
        }

        $pixurl = $this->moodle->user_picture_url($record->id);

        return array(
            'username' => $username,
            'firstname' => $record->firstname,
            'lastname' => $record->lastname,
            'email' => $record->email,
            'picture' => $record->picture ? $pixurl : null,
            'lastaccess' => (int) $record->lastaccess,
        );
    }

    function get_user_lastaccess($users) {
        $usernames = array();
        foreach ($users as $username) {
            if (!$userid = $this->moodle->get_user_id($username)) {
                throw new local_secretaria_exception('Unknown user');
            }
            $usernames[$userid] = $username;
        }

        $result = array();

        if ($records = $this->moodle->get_user_lastaccess(array_keys($usernames))) {
            foreach ($records as $record) {
                $result[] = array('user' => $usernames[$record->userid],
                                  'course' => $record->course,
                                  'time' => (int) $record->time);
            }
        }

        return $result;
    }

    function create_user($properties) {
        if (!$properties['username'] or !$properties['firstname'] or !$properties['lastname']) {
            throw new local_secretaria_exception('Invalid parameters');
        }

        if ($this->moodle->get_user_id($properties['username'])) {
            throw new local_secretaria_exception('Duplicate username');
        }

        $auth = $this->moodle->auth_plugin();

        if ($this->moodle->prevent_local_passwords($auth)) {
            $properties['password'] = false;
        } elseif (!isset($properties['password']) or
                  !$this->moodle->check_password($properties['password'])) {
            throw new local_secretaria_exception('Invalid password');
        }

        $this->moodle->start_transaction();
        $this->moodle->create_user(
            $auth,
            $properties['username'],
            $properties['password'],
            $properties['firstname'],
            $properties['lastname'],
            isset($properties['email']) ? $properties['email'] : ''
        );
        $this->moodle->commit_transaction();
    }

    function update_user($username, $properties) {
        if (!$user = $this->moodle->get_user($username)) {
            throw new local_secretaria_exception('Unknown user');
        }

        $password = false;

        if (isset($properties['username'])) {
            if (empty($properties['username'])) {
                throw new local_secretaria_exception('Invalid parameters');
            }
            $newuserid = $this->moodle->get_user_id($properties['username']);
            if ($newuserid and $newuserid !== $user->id) {
                throw new local_secretaria_exception('Duplicate username');
            }
        }

        if (isset($properties['password'])) {
            if (!$this->moodle->prevent_local_passwords($user->auth)) {
                if (!$this->moodle->check_password($properties['password'])) {
                    throw new local_secretaria_exception('Invalid password');
                }
                $password = $properties['password'];
            }
            unset($properties['password']);
        }

        if (isset($properties['firstname']) and empty($properties['firstname'])) {
            throw new local_secretaria_exception('Invalid parameters');
        }

        if (isset($properties['lastname']) and empty($properties['lastname'])) {
            throw new local_secretaria_exception('Invalid parameters');
        }

        $this->moodle->start_transaction();
        if ($properties) {
            $properties['id'] = $user->id;
            $this->moodle->update_user((object) $properties);
        }
        if ($password) {
            $this->moodle->update_password($user->id, $password);
        }
        $this->moodle->commit_transaction();
    }

    function delete_user($username) {
        if (!$userid = $this->moodle->get_user_id($username)) {
            throw new local_secretaria_exception('Unknown user');
        }
        $this->moodle->start_transaction();
        $this->moodle->delete_user($userid);
        $this->moodle->commit_transaction();
    }

    function get_users() {
        $result = array();
        if ($records = $this->moodle->get_users()) {
            foreach ($records as $record) {
                $result[] = $record->username;
            }
        }
        return $result;
    }

    /* Courses */

    function has_course($shortname) {
        return (bool) $this->moodle->get_course_id($shortname);
    }

    function get_course($shortname) {
        if (!$record = $this->moodle->get_course($shortname)) {
            throw new local_secretaria_exception('Unknown course');
        }

        $date = getdate($record->startdate);
        return array(
            'shortname' => $record->shortname,
            'fullname' => $record->fullname,
            'visible' => (bool) $record->visible,
            'startdate' => array(
                'year' => $date['year'],
                'month' => $date['mon'],
                'day' => $date['mday'],
            ),
        );
    }

    function update_course($shortname, $properties) {
        if (!$courseid = $this->moodle->get_course_id($shortname)) {
            throw new local_secretaria_exception('Unknown course');
        }

        $record = new stdClass;
        $record->id = $courseid;

        if (isset($properties['shortname'])) {
            if (empty($properties['shortname'])) {
                throw new local_secretaria_exception('Invalid parameters');
            }
            $otherid = $this->moodle->get_course_id($properties['shortname']);
            if ($otherid and $otherid != $courseid) {
                throw new local_secretaria_exception('Duplicate shortname');
            }
            $record->shortname = $properties['shortname'];
        }

        if (isset($properties['fullname'])) {
            if (empty($properties['fullname'])) {
                throw new local_secretaria_exception('Invalid parameters');
            }
            $record->fullname = $properties['fullname'];
        }

        if (isset($properties['visible'])) {
            $record->visible = (int) $properties['visible'];
        }

        if (isset($properties['startdate'])) {
            $record->startdate = mktime(0, 0, 0,
                                        $properties['startdate']['month'],
                                        $properties['startdate']['day'],
                                        $properties['startdate']['year']);
        }

        $this->moodle->update_course($record);
    }

    function get_courses() {
        $result = array();
        if ($records = $this->moodle->get_courses()) {
            foreach ($records as $record) {
                $result[] = $record->shortname;
            }
        }
        return $result;
    }

    /* Enrolments */

    function get_course_enrolments($course) {
        if (!$courseid = $this->moodle->get_course_id($course)) {
            throw new local_secretaria_exception('Unknown course');
        }

        $enrolments = array();
        if ($records = $this->moodle->get_role_assignments_by_course($courseid)) {
            foreach ($records as $record) {
                $enrolments[] = array('user' => $record->user, 'role' => $record->role);
            }
        }

        return $enrolments;
    }

    function get_user_enrolments($username) {
        if (!$userid = $this->moodle->get_user_id($username)) {
            throw new local_secretaria_exception('Unknown user');
        }

        $enrolments = array();
        if ($records = $this->moodle->get_role_assignments_by_user($userid)) {
            foreach ($records as $record) {
                $enrolments[] = array('course' => $record->course, 'role' => $record->role);
            }
        }

        return $enrolments;
    }

    function enrol_users($enrolments) {
        $this->moodle->start_transaction();

        foreach ($enrolments as $enrolment) {
            if (!$courseid = $this->moodle->get_course_id($enrolment['course'])) {
                throw new local_secretaria_exception('Unknown course');
            }
            if (!$userid = $this->moodle->get_user_id($enrolment['user'])) {
                continue;
            }
            if (!$roleid = $this->moodle->get_role_id($enrolment['role'])) {
                throw new local_secretaria_exception('Unknown role');
            }
            if (!$this->moodle->role_assignment_exists($courseid, $userid, $roleid)) {
                $this->moodle->insert_role_assignment($courseid, $userid, $roleid);
            }
        }

        $this->moodle->commit_transaction();
    }

    function unenrol_users($enrolments) {
        $this->moodle->start_transaction();

        foreach ($enrolments as $enrolment) {
            if (!$courseid = $this->moodle->get_course_id($enrolment['course'])) {
                throw new local_secretaria_exception('Unknown course');
            }
            if (!$userid = $this->moodle->get_user_id($enrolment['user'])) {
                continue;
            }
            if (!$roleid = $this->moodle->get_role_id($enrolment['role'])) {
                throw new local_secretaria_exception('Unknown role');
            }
            $this->moodle->delete_role_assignment($courseid, $userid, $roleid);
        }

        $this->moodle->commit_transaction();
    }

    /* Groups */

    function get_groups($course) {
        if (!$courseid = $this->moodle->get_course_id($course)) {
            throw new local_secretaria_exception('Unknown course');
        }

        $groups = array();

        if ($records = $this->moodle->groups_get_all_groups($courseid)) {
            foreach ($records as $record) {
                $groups[] = array('name' => $record->name,
                                  'description' => $record->description);
            }
        }

        return $groups;
    }

    function create_group($course, $name, $description) {
        if (!$courseid = $this->moodle->get_course_id($course)) {
            throw new local_secretaria_exception('Unknown course');
        }
        if (empty($name)) {
            throw new local_secretaria_exception('Invalid parameters');
        }
        if ($this->moodle->get_group_id($courseid, $name)) {
            throw new local_secretaria_exception('Duplicate group');
        }
        $this->moodle->start_transaction();
        $this->moodle->groups_create_group($courseid, $name, $description);
        $this->moodle->commit_transaction();
    }

    function delete_group($course, $name) {
        if (!$courseid = $this->moodle->get_course_id($course)) {
            throw new local_secretaria_exception('Unknown course');
        }
        if (!$groupid = $this->moodle->get_group_id($courseid, $name)) {
            throw new local_secretaria_exception('Unknown group');
        }
        $this->moodle->start_transaction();
        $this->moodle->groups_delete_group($groupid);
        $this->moodle->commit_transaction();
    }

    function get_group_members($course, $name) {
        if (!$courseid = $this->moodle->get_course_id($course)) {
            throw new local_secretaria_exception('Unknown course');
        }
        if (!$groupid = $this->moodle->get_group_id($courseid, $name)) {
            throw new local_secretaria_exception('Unknown group');
        }
        $users = array();
        if ($records = $this->moodle->get_group_members($groupid)) {
            foreach ($records as $record) {
                $users[] = $record->username;
            }
        }
        return $users;
    }

    function add_group_members($course, $name, $users) {
        if (!$courseid = $this->moodle->get_course_id($course)) {
            throw new local_secretaria_exception('Unknown course');
       }
        if (!$groupid = $this->moodle->get_group_id($courseid, $name)) {
            throw new local_secretaria_exception('Unknown group');
        }

        $this->moodle->start_transaction();

        foreach ($users as $user) {
            if (!$userid = $this->moodle->get_user_id($user)) {
                continue;
            }
            $this->moodle->groups_add_member($groupid, $userid);
        }

        $this->moodle->commit_transaction();
    }

    function remove_group_members($course, $name, $users) {
        if (!$courseid = $this->moodle->get_course_id($course)) {
            throw new local_secretaria_exception('Unknown course');
        }
        if (!$groupid = $this->moodle->get_group_id($courseid, $name)) {
            throw new local_secretaria_exception('Unknown group');
        }

        $this->moodle->start_transaction();

        foreach ($users as $user) {
            if (!$userid = $this->moodle->get_user_id($user)) {
                continue;
            }
            $this->moodle->groups_remove_member($groupid, $userid);
        }

        $this->moodle->commit_transaction();
    }

    function get_user_groups($user, $course) {
        if (!$userid = $this->moodle->get_user_id($user)) {
            throw new local_secretaria_exception('Unknown user');
        }
        if (!$courseid = $this->moodle->get_course_id($course)) {
            throw new local_secretaria_exception('Unknown course');
        }

        $groups = array();

        if ($records = $this->moodle->groups_get_all_groups($courseid, $userid)) {
            foreach ($records as $record) {
                $groups[] = $record->name;
            }
        }

        return $groups;
    }

    /* Grades */

    function get_course_grades($course, $users) {
        if (!$courseid = $this->moodle->get_course_id($course)) {
            throw new local_secretaria_exception('Unknown course');
        }

        $usernames = array();
        foreach ($users as $user) {
            if (!$userid = $this->moodle->get_user_id($user)) {
                throw new local_secretaria_exception('Unknown user');
            }
            $usernames[$userid] = $user;
        }
        $userids = array_keys($usernames);

        $result = array();

        $items = $this->moodle->get_grade_items($courseid);
        usort($items, function ($a, $b) {
            return $a['sortorder'] - $b['sortorder'];
        });

        foreach ($items as $item) {
            $grades = array();
            if ($userids) {
                foreach ($this->moodle->get_grades($item['id'], $userids) as $userid => $grade) {
                    $grades[] = array('user' => $usernames[$userid], 'grade' => $grade);
                }
            }
            $result[] = array(
                'idnumber' => $item['idnumber'] ?: '',
                'type' => $item['type'],
                'module' => $item['module'],
                'name' => $item['name'],
                'grademin' => $item['grademin'],
                'grademax' => $item['grademax'],
                'gradepass' => $item['gradepass'],
                'grades' => $grades,
            );
        }

        return $result;
    }

    function get_user_grades($user, $courses)  {
        if (!$userid = $this->moodle->get_user_id($user)) {
            throw new local_secretaria_exception('Unknown user');
        }

        $result = array();

        foreach ($courses as $course) {
            if (!$courseid = $this->moodle->get_course_id($course)) {
                throw new local_secretaria_exception('Unknown course');
            }
            $result[] = array(
                'course' => $course,
                'grade' => $this->moodle->get_course_grade($userid, $courseid),
            );
        }

        return $result;
    }

    /* Assignments */

    function get_assignments($course) {
        if (!$courseid = $this->moodle->get_course_id($course)) {
            throw new local_secretaria_exception('Unknown course');
        }

        $result = array();

        if ($records = $this->moodle->get_assignments($courseid)) {
            foreach ($records as $record) {
                $result[] = array(
                    'idnumber' => $record->idnumber ?: '',
                    'name' => $record->name,
                    'opentime' => (int) $record->opentime ?: null,
                    'closetime' => (int) $record->closetime ?: null,
                );
            }
        }

        return $result;
    }

    function get_assignment_submissions($course, $idnumber) {
        if (!$idnumber) {
            throw new local_secretaria_exception('Invalid parameters');
        }

        if (!$courseid = $this->moodle->get_course_id($course)) {
            throw new local_secretaria_exception('Unknown course');
        }

        if (!$assignmentid = $this->moodle->get_assignment_id($courseid, $idnumber)) {
            throw new local_secretaria_exception('Unknown assignment');
        }

        $result = array();

        if ($records = $this->moodle->get_assignment_submissions($assignmentid)) {
            foreach ($records as $record) {
                $result[] = array(
                    'user' => $record->user,
                    'grader' => $record->grader,
                    'timesubmitted' => (int) $record->timesubmitted,
                    'timegraded' => (int) $record->timegraded ?: null,
                    'numfiles' => (int) $record->numfiles,
                    'attempt' => (int) $record->attempt,
                );
            }
        }

        return $result;
    }

    /* Forums */

    function get_forum_stats($course) {
        if (!$courseid = $this->moodle->get_course_id($course)) {
            throw new local_secretaria_exception('Unknown course');
        }

        $result = array();

        if ($forums = $this->moodle->get_forums($courseid)) {
            foreach ($forums as $forum) {
                $stats = array();
                if ($records = $this->moodle->get_forum_stats($forum->id)) {
                    foreach ($records as $record) {
                        $stats[] = array(
                            'group' => $record->groupname ?: '',
                            'discussions' => (int) $record->discussions,
                            'posts' => (int) $record->posts,
                        );
                    }
                }
                $result[] = array(
                    'idnumber' => $forum->idnumber ?: '',
                    'name' => $forum->name,
                    'type' => $forum->type,
                    'stats' => $stats,
                );
            }
        }

        return $result;
    }

    function get_forum_user_stats($course, $users) {
        if (!$courseid = $this->moodle->get_course_id($course)) {
            throw new local_secretaria_exception('Unknown course');
        }

        $result = array();

        if ($forums = $this->moodle->get_forums($courseid)) {
            foreach ($forums as $forum) {
                $stats = array();
                if ($records = $this->moodle->get_forum_user_stats($forum->id, $users)) {
                    foreach ($records as $record) {
                        $stats[] = array(
                            'username' => $record->username,
                            'group' => $record->groupname?:'',
                            'discussions' => $record->discussions,
                            'posts' => $record->posts,
                        );
                    }
                }
                $result[] = array(
                    'idnumber' => $forum->idnumber ?: '',
                    'name' => $forum->name,
                    'type' => $forum->type,
                    'stats' => $stats,
                );
            }
        }

        return $result;
    }

    /* Surveys */

    function get_surveys($course) {
        $result = array();

        if (!$courseid = $this->moodle->get_course_id($course)) {
            throw new local_secretaria_exception('Unknown course');
        }

        if ($records = $this->moodle->get_surveys($courseid)) {
            foreach ($records as $record) {
                $result[] = array(
                    'idnumber' => $record->idnumber ?: '',
                    'name' => $record->name,
                    'type' => $record->realm,
                );
            }
        }

        return $result;
    }

    function create_survey($properties) {
        if (empty($properties['idnumber']) or
            empty($properties['name']) or
            empty($properties['summary']) or
            empty($properties['template']['course']) or
            empty($properties['template']['idnumber'])) {
            throw new local_secretaria_exception('Invalid parameters');
        }

        if (!$courseid = $this->moodle->get_course_id($properties['course'])) {
            throw new local_secretaria_exception('Unknown course');
        }

        if (!$this->moodle->section_exists($courseid, $properties['section'])) {
            throw new local_secretaria_exception('Unknown section');
        }

        if ($this->moodle->get_survey_id($courseid, $properties['idnumber'])) {
            throw new local_secretaria_exception('Duplicate idnumber');
        }

        if (!$templatecourseid = $this->moodle->get_course_id($properties['template']['course'])) {
            throw new local_secretaria_exception('Unknown course');
        }

        if (!$templateid = $this->moodle->get_survey_id($templatecourseid,
                                                        $properties['template']['idnumber'])) {
            throw new local_secretaria_exception('Unknown survey');
        }

        $opendate = (isset($properties['opendate']) ?
                     mktime(0, 0, 0,
                            $properties['opendate']['month'],
                            $properties['opendate']['day'],
                            $properties['opendate']['year'])
                     : 0);

        $closedate = (isset($properties['closedate']) ?
                      mktime(23, 55, 0,
                             $properties['closedate']['month'],
                             $properties['closedate']['day'],
                             $properties['closedate']['year'])
                      : 0);

        $this->moodle->start_transaction();
        $this->moodle->create_survey($courseid, $properties['section'], $properties['idnumber'],
                                     $properties['name'], $properties['summary'],
                                     $opendate, $closedate, $templateid);
        $this->moodle->commit_transaction();
    }

    /* Mail */

    function send_mail($message) {
        if (!$courseid = $this->moodle->get_course_id($message['course'])) {
            throw new local_secretaria_exception('Unknown course');
        }

        $usernames = array_merge(array($message['sender']), $message['to']);
        if (isset($message['cc'])) {
            $usernames = array_merge($usernames, $message['cc']);
        }
        if (isset($message['bcc'])) {
            $usernames = array_merge($usernames, $message['bcc']);
        }
        if (!$message['to'] or count($usernames) != count(array_unique($usernames))) {
            throw new local_secretaria_exception('Invalid parameters');
        }

        $sender = false;
        $to = array();
        $cc = array();
        $bcc = array();

        foreach ($usernames as $username) {
            if (!$userid = $this->moodle->get_user_id($username)) {
                throw new local_secretaria_exception('Unknown user');
            }
            if ($username == $message['sender']) {
                $sender = $userid;
            } else if (in_array($username, $message['to'])) {
                $to[] = $userid;
            } else if (in_array($username, $message['cc'])) {
                $cc[] = $userid;
            } else if (in_array($username, $message['bcc'])) {
                $bcc[] = $userid;
            }
        }

        $this->moodle->send_mail($sender, $courseid, $message['subject'],
                                 $message['content'], $to, $cc, $bcc);
    }

    function get_mail_stats($user, $starttime, $endtime) {
        if (!$userid = $this->moodle->get_user_id($user)) {
            throw new local_secretaria_exception('Unknown user');
        }

        $courses = array();
        $received = array();
        $sent = array();

        if ($records = $this->moodle->get_mail_stats_received($userid, $starttime, $endtime)) {
            foreach ($records as $id => $record) {
                $courses[$id] = $record->course;
                $received[$id] = (int) $record->messages;
            }
        }

        if ($records = $this->moodle->get_mail_stats_sent($userid, $starttime, $endtime)) {
            foreach ($records as $id => $record) {
                $courses[$id] = $record->course;
                $sent[$id] = (int) $record->messages;
            }
        }

        $result = array();

        foreach ($courses as $id => $course) {
            $result[] = array(
                'course' => $course,
                'received' => isset($received[$id]) ? $received[$id] : 0,
                'sent' => isset($sent[$id]) ? $sent[$id] : 0,
            );
        }

        return $result;
    }
}

interface local_secretaria_moodle {
    function auth_plugin();
    function check_password($password);
    function commit_transaction();
    function create_survey($courseid, $section, $name, $summary, $idnumber,
                           $opendate, $closedate, $templateid);
    function create_user($auth, $username, $password, $firstname, $lastname, $email);
    function delete_user($record);
    function delete_role_assignment($courseid, $userid, $roleid);
    function get_assignment_id($courseid, $idnumber);
    function get_assignment_submissions($assignmentid);
    function get_assignments($courseid);
    function get_course($shortname);
    function get_course_id($shortname);
    function get_courses();
    function get_course_grade($userid, $courseid);
    function get_forum_stats($forumid);
    function get_forum_user_stats($forumid, $users);
    function get_forums($courseid);
    function get_grade_items($courseid);
    function get_grades($itemid, $userids);
    function get_group_id($courseid, $name);
    function get_group_members($groupid);
    function get_mail_stats_sent($userid, $starttime, $endtime);
    function get_mail_stats_received($userid, $starttime, $endtime);
    function get_role_assignments_by_course($courseid);
    function get_role_assignments_by_user($userid);
    function get_role_id($role);
    function get_survey_id($courseid, $idnumber);
    function get_surveys($courseid);
    function get_user($username);
    function get_user_id($username);
    function get_user_lastaccess($userids);
    function get_users();
    function groups_add_member($groupid, $userid);
    function groups_create_group($courseid, $name, $description);
    function groups_delete_group($groupid);
    function groups_get_all_groups($courseid, $userid=0);
    function groups_remove_member($groupid, $userid);
    function insert_role_assignment($courseid, $userid, $roleid);
    function prevent_local_passwords($auth);
    function role_assignment_exists($courseid, $userid, $roleid);
    function rollback_transaction(Exception $e);
    function section_exists($courseid, $section);
    function send_mail($sender, $courseid, $subject, $content, $to, $cc, $bcc);
    function start_transaction();
    function update_course($record);
    function update_password($userid, $password);
    function update_user($user);
    function user_picture_url($userid);
}
