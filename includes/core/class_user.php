<?php

class User {

    // GENERAL

    public static function user_info($d) {
        // vars
        $user_id = isset($d['user_id']) && is_numeric($d['user_id']) ? $d['user_id'] : 0;
        $phone = isset($d['phone']) ? preg_replace('~\D+~', '', $d['phone']) : 0;
        // where
        if ($user_id) $where = "user_id='".$user_id."'";
        else if ($phone) $where = "phone='".$phone."'";
        else return [];
        // info
        $q = DB::query("SELECT user_id, phone, access, email, first_name, last_name, plot_id, FROM_UNIXTIME(last_login) last_login FROM users WHERE ".$where." LIMIT 1;") or die (DB::error());
        if ($row = DB::fetch_row($q)) {
            return [
                'id' => (int) $row['user_id'],
                'access' => (int) $row['access'],
                'email' => $row['email'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'phone' => $row['phone'],
                'plot_id' => $row['plot_id'],
                'last_login' => $row['last_login'],
            ];
        } else {
            return [
                'id' => 0,
                'access' => 0
            ];
        }
    }

    public static function users_list_plots($number) {
        // vars
        $items = [];
        // info
        $q = DB::query("SELECT user_id, plot_id, first_name, email, phone
            FROM users WHERE plot_id LIKE '%".$number."%' ORDER BY user_id;") or die (DB::error());
        while ($row = DB::fetch_row($q)) {
            $plot_ids = explode(',', $row['plot_id']);
            $val = false;
            foreach($plot_ids as $plot_id) if ($plot_id == $number) $val = true;
            if ($val) $items[] = [
                'id' => (int) $row['user_id'],
                'first_name' => $row['first_name'],
                'email' => $row['email'],
                'phone_str' => phone_formatting($row['phone'])
            ];
        }
        // output
        return $items;
    }

    public static function users_list($d = []) {
        // vars
        $search = isset($d['search']) && trim($d['search']) ? $d['search'] : '';
        $offset = isset($d['offset']) && is_numeric($d['offset']) ? $d['offset'] : 0;
        $limit = 20;
        $items = [];
        // where
        $where = [];
        if ($search) {
          $where[] = "phone LIKE '%".$search."%'";
          $where[] = "email LIKE '%".$search."%'";
          $where[] = "first_name LIKE '%".$search."%'";
          $where[] = "last_name LIKE '%".$search."%'";
        }
        $where = $where ? "WHERE ".implode(" OR ", $where) : "";
        // info
        $q = DB::query("SELECT user_id, first_name, last_name, email, phone, plot_id, FROM_UNIXTIME(last_login) last_login
            FROM users ".$where." ORDER BY user_id+0 LIMIT ".$offset.", ".$limit.";") or die (DB::error());
        while ($row = DB::fetch_row($q)) {
            $items[] = [
                'id' => (int) $row['user_id'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'plot_id' => $row['plot_id'],
                'last_login' => $row['last_login'],
                #'users' => $row['number'] ? User::users_list_plots($row['number']) : [],
            ];
        }
        // paginator
        $q = DB::query("SELECT count(*) FROM users ".$where.";");
        $count = ($row = DB::fetch_row($q)) ? $row['count(*)'] : 0;
        $url = 'users?';
        if ($search) $url .= '&search='.$search;
        paginator($count, $offset, $limit, $url, $paginator);
        // output
        return ['items' => $items, 'paginator' => $paginator];
    }

    public static function users_fetch($d = []) {
        $info = User::users_list($d);
        HTML::assign('users', $info['items']);
        return ['html' => HTML::fetch('./partials/users_table.html'), 'paginator' => $info['paginator']];
    }

    // ACTIONS

    public static function user_edit_window($d = []) {
        $user_id = isset($d['user_id']) && is_numeric($d['user_id']) ? $d['user_id'] : 0;
        HTML::assign('user', User::user_info(['user_id' => $user_id]));
        return ['html' => HTML::fetch('./partials/user_edit.html')];
    }

    public static function user_edit_update($d = []) {
        // vars
        $user_id = isset($d['user_id']) && is_numeric($d['user_id']) ? $d['user_id'] : 0;
        $first_name = $d['first_name'];
        $last_name = $d['last_name'];
        $email = strtolower($d['email']);
        $phone = preg_replace('~\D+~', '', $d['phone']);
        if ($d['plot_id'])
          $plots = preg_split("~\D+~",$d['plot_id']);
        foreach ([$first_name,$last_name,$email,$phone] as $req_field) {
          if (!$req_field) die("Obligatory fields not filled");
        }
        if ($plots) {
          //WARN: assuming group_concat_max_len variable is long enough; else we'd have to set it via separate query.
          $qplots = DB::query("SELECT GROUP_CONCAT(plot_id) plots FROM plots WHERE plot_id IN (" . join(",",$plots) . ")");
          $plots = DB::fetch_row($qplots);
          $plots = $plots['plots'];
        }
        // update
        if ($user_id) {
            $set = [];
            $set[] = "first_name='".$first_name."'";
            $set[] = "last_name='".$last_name."'";
            $set[] = "email='".$email."'";
            $set[] = "phone='".$phone."'";
            $set[] = "plot_id='".$plots."'";
            $set = implode(", ", $set);
            DB::query("UPDATE users SET ".$set." WHERE user_id='".$user_id."' LIMIT 1;") or die (DB::error());
        } else {
            DB::query("INSERT INTO users (
                first_name,
                last_name,
                email,
                phone,
                plot_id,
                updated
            ) VALUES (
                '".$first_name."',
                '".$last_name."',
                '".$email."',
                '".$phone."',
                '".$plots."',
                '".Session::$ts."'
            );") or die (DB::error());
        }
        // output
        return User::users_fetch(['offset' => $offset]);
    }

    public static function user_remove($d = []) {
        // vars
        $user_id = isset($d['user_id']) && is_numeric($d['user_id']) ? $d['user_id'] : 0;
        if ($user_id) {
          DB::query("DELETE FROM users WHERE user_id = $user_id");
        }
        return 'ok';
    }
}
