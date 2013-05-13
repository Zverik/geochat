<? // GeoChat for OpenStreetMap

const RADIUS = 30; // visibility radius in km
const AGE = 5; // how long in hours keep messages
const USER_AGE = 1; // how long in hours keep users

$db = new mysqli('localhost', 'zverik', '', 'geochat');
if( $db->connect_errno )
    die('Cannot connect to database: ('.$db->connect_errno.') '.$db->connect_error);
$db->set_charset('utf8');

if( PHP_SAPI == 'cli' ) {
    maintenance($db);
    exit;
}

header('Access-Control-Allow-Origin: *');
header('Content-type: application/json');
$action = req('action', '');
if( $action == 'get' || $action == 'post' || $action == 'register' ) {
    $lat = req('lat'); validate_num($lat, 'lat', true, -90.0, 90.0);
    $lon = req('lon'); validate_num($lon, 'lon', true, -180.0, 180.0);
    if( $action == 'get' )
        get($db, $lat, $lon);
    elseif( $action == 'post' )
        post($db, $lat, $lon);
    elseif( $action == 'register' )
        register($db, $lat, $lon);
} elseif( $action == 'logout' ) {
    logout($db);
} elseif( $action == 'whoami' ) {
    $user_name = validate_user($db, req('uid'));
    print json_encode(array('name' => $user_name));
} elseif( $action == 'now' ) {
    $now = request_one($db, 'select now()');
    print json_encode(array('date' => $now));
} elseif( $action == 'last' ) {
    get_last($db);
} else {
//    header('Content-type: text/html; charset=utf-8');
//    readfile('osmochat.html');
    header('Location: http://wiki.openstreetmap.org/wiki/JOSM/Plugins/GeoChat/API');
}

// Print error message and exit
function error( $msg ) {
    print json_encode(array('error' => $msg));
    exit;
}

// Check query parameter and return either it or the default value, or raise error if there's no default.
function req( $param, $default = NULL ) {
    if( !isset($_REQUEST[$param]) || strlen($_REQUEST[$param]) == 0 ) {
        if( is_null($default) )
            error("Missing required parameter \"$param\".");
        else
            return $default;
    }
    return trim($_REQUEST[$param]);
}

// Validate float or integer number, and check for min/max.
function validate_num( $f, $name, $float, $min = NULL, $max = NULL ) {
    if( !preg_match($float ? '/^-?\d+(?:\.\d+)?$/' : '/^-?\d+$/', $f) )
        error("Parameter \"$name\" should be "
             .($float ? 'a floating-point number with a dot as a separator.' : 'an integer number'));
    if( (!is_null($min) && $f < $min) || (!is_null($max) && $f > $max) )
        error("Parameter \"$name\" should be a number between $min and $max.");
}

// Request a single value from the database
function request_one($db, $query) {
    $result = $db->query($query);
    if( !$result )
        error('Database query failed: '.$db->error);
    if( $result->num_rows > 0 ) {
        $tmp = $result->fetch_row();
        $ret = $tmp[0];
    } else {
        $ret = null;
    }
    $result->free();
    return $ret;
}

// Check that user exists and returns their name
function validate_user( $db, $user_id ) {
    validate_num($user_id, 'uid', false);
    $user_name = request_one($db, 'select user_name from osmochat_users where user_id = '.$user_id);
    if( !$user_name )
        error("No user with user_id $user_id found");
    return $user_name;
}

// Returns where clause for offsets around a point with given radius in km
function region_where_clause( $lat, $lon, $radius, $field ) {
    $basekm = 6371.0;
    $coslat = cos($lat * M_PI / 180.0);
    $dlat = ($radius / $basekm);
    $dlon = asin(sin($dlat) / $coslat) * 180.0 / M_PI;
    $dlat = $dlat * 180.0 / M_PI;
    $minlat = $lat - $dlat;
    $minlon = $lon - $dlon;
    $maxlat = $lat + $dlat;
    $maxlon = $lon + $dlon;
    $bbox = "GeomFromText('POLYGON(($minlon $minlat, $minlon $maxlat, $maxlon $maxlat, $maxlon $minlat, $minlon $minlat))')";
    return "MBRContains($bbox, $field)";
}

// Prints all messages near the specified point
// Also registers user coords and returns all nearby users
function get( $db, $lat, $lon ) {
    $user_id = req('uid', 0);
    validate_user($db, $user_id);
    $last = req('last', 0);
    validate_num($last, 'last', false);
    $list = array();

    $result = $db->query("update osmochat_users set last_time = NOW(), last_pos = POINT($lon, $lat) where user_id = $user_id");
    if( !$result )
        error('Failed to update user position: '.$db->error);

    $region = region_where_clause($lat, $lon, RADIUS, 'msgpos');
    $query = "select *, X(msgpos) as lon, Y(msgpos) as lat, unix_timestamp(msgtime) as ts from osmochat where msgid > $last and ((recipient is null and $region) or recipient = $user_id or (recipient is not null and author = $user_id)) order by msgid desc limit 30";
    $result = $db->query($query);
    if( !$result )
        error('Database query for messages failed: '.$db->error);
    $msg = array();
    $pmsg = array();
    while( ($data = $result->fetch_assoc()) ) {
        $item = array();
        $item['id'] = $data['msgid'];
        $item['lat'] = $data['lat'];
        $item['lon'] = $data['lon'];
        $item['time'] = $data['msgtime'];
        $item['timestamp'] = $data['ts'];
        $item['author'] = $data['user_name'];
        $item['message'] = $data['message'];
        $item['incoming'] = $data['author'] != $user_id;
        if( !is_null($data['recipient']) ) {
            $item['recipient'] = $data['recipient_name'];
            array_unshift($pmsg, $item);
        } else
            array_unshift($msg, $item);
    }
    $list['messages'] = $msg;
    $list['private'] = $pmsg;
    $result->free();

    $region = region_where_clause($lat, $lon, RADIUS, 'last_pos');
    $query = "select user_name, X(last_pos) as lon, Y(last_pos) as lat from osmochat_users where user_id != $user_id and $region limit 100";
    $result = $db->query($query);
    if( !$result )
        error('Database query for users failed: '.$db->error);
    $users = array();
    while( ($data = $result->fetch_assoc()) ) {
        $item = array();
        $item['user'] = $data['user_name'];
        $item['lat'] = $data['lat'];
        $item['lon'] = $data['lon'];
        $users[] = $item;
    }
    $list['users'] = $users;
    $result->free();

    print json_encode($list);
}

// Returns last messages with little extra info (unusable for chatting)
function get_last( $db ) {
    $last = req('last', 0);
    validate_num($last, 'last', false);
    $list = array();

    $query = "select *, X(msgpos) as lon, Y(msgpos) as lat, unix_timestamp(msgtime) as ts from osmochat where msgid > $last and recipient is null order by msgid desc limit 20";
    $result = $db->query($query);
    if( !$result )
        error('Database query for messages failed: '.$db->error);
    while( ($data = $result->fetch_assoc()) ) {
        $item = array();
        $item['id'] = $data['msgid'];
        $item['lat'] = $data['lat'];
        $item['lon'] = $data['lon'];
        $item['time'] = $data['msgtime'];
        $item['timestamp'] = $data['ts'];
        $item['author'] = $data['user_name'];
        $item['message'] = $data['message'];
        array_unshift($list, $item);
    }
    $result->free();

    print json_encode($list);
}

// Adds a message to the database
function post( $db, $lat, $lon ) {
    $message = $db->escape_string(req('message'));
    if( mb_strlen($message, 'UTF8') == 0 || mb_strlen($message, 'UTF8') > 1000 )
        error('Incorrect message');
    $user_id = req('uid');
    $user_name = validate_user($db, $user_id);

    $to = req('to', '');
    if( strlen($to) >= 2 ) {
        // This message is private
        $recipient = request_one($db, "select user_id from osmochat_users where user_name = '".$db->escape_string($to)."'");
        if( !$recipient )
            error("No user with the name '$to'");
        $recname = "'".$db->escape_string($to)."'";
    } else {
        $recipient = 'NULL';
        $recname = 'NULL';
    }

    $query = "insert into osmochat (msgtime, msgpos, user_name, author, recipient, recipient_name, message) values (now(), POINT($lon, $lat), '$user_name', $user_id, $recipient, $recname, '$message')";
    $result = $db->query($query);
    if( !$result )
        error('Failed to add message entry: '.$db->error);
    print json_encode(array('message' => 'Message was successfully added'));
}

// Register a user. Returns his user_id
function register($db, $lat, $lon) {
    $user_name = $db->escape_string(req('name'));
    if( strpos($user_name, ' ') !== FALSE || mb_strlen($user_name, 'UTF8') < 2 || mb_strlen($user_name, 'UTF8') > 100 )
        error('Incorrect user name');
    if( request_one($db, "select user_id from osmochat_users where user_name = '$user_name'") )
        error("User $user_name is already logged in, please choose another name");
    $tries = 0;
    while( !isset($user_id) ) {
        $user_id = mt_rand(1, 2147483647);
        $res = request_one($db, "select user_id from osmochat_users where user_id = $user_id");
        if( $res ) {
            unset($user_id);
            if( ++$tries >= 10 )
                error("Could not invent user id after $tries tries");
        }
    }
    $result = $db->query("insert into osmochat_users (user_id, user_name, last_time, last_pos) values($user_id, '$user_name', now(), POINT($lon, $lat))");
    if( !$result )
        error('Database error: '.$db->error);
    print json_encode(array('message' => 'The user has been registered', 'uid' => $user_id));
}

// Log out a user by user_id
function logout($db) {
    $user_id = req('uid');
    validate_user($db, $user_id);
    $result = $db->query("delete from osmochat_users where user_id = $user_id");
    if( !$result )
        error('Database error: '.$db->error);
    print json_encode(array('message' => 'The user has been logged out'));
}

// Create the table if it does not exists
// Delete all messages older than AGE
function maintenance($db) {
    $res = $db->query("show tables like 'osmochat'");
    if( $res->num_rows == 0 ) {
        print("Creating the tables: osmochat");
        $query = <<<CSQL
create table osmochat (
    msgid int unsigned not null auto_increment primary key,
    msgtime datetime not null,
    msgpos point not null,
    author int not null,
    recipient int,
    user_name varchar(100) not null,
    recipient_name varchar(100),
    message varchar(1000) not null,
    index(msgtime),
    index(recipient),
    spatial index(msgpos)
) Engine=MyISAM DEFAULT CHARACTER SET utf8
CSQL;
        $result = $db->query($query);
        if( !$result ) {
            print " - failed: ".$db->error."\n";
            exit;
        }
        print ', osmochat_users';
        $db->query('drop table if exists osmochat_users');
        $query = <<<CSQL
create table osmochat_users (
    user_id int not null primary key,
    user_name varchar(100) not null,
    last_time datetime not null,
    last_pos point not null,
    index(user_name),
    spatial index(last_pos)
) Engine=MyISAM DEFAULT CHARACTER SET utf8
CSQL;
        $result = $db->query($query);
        if( !$result ) {
            print " - failed: ".$db->error."\n";
            exit;
        }
        print " OK\n";
    } else {
        print("Removing old messages...");
        $query = 'delete from osmochat where msgtime < now() - interval '.AGE.' hour';
        $result = $db->query($query);
        print(!$result ? 'Failed: '.$db->error."\n" : "OK\n");

        print("Removing old users...");
        $query = 'delete from osmochat_users where last_time < now() - interval '.USER_AGE.' hour';
        $result = $db->query($query);
        print(!$result ? 'Failed: '.$db->error."\n" : "OK\n");
    }
}

?>
