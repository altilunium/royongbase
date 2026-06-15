<?php
// Configuration and Cryptography
$app_key = 'your-super-secret-app-wide-key!'; // Change this in production
$cipher = 'AES-256-CBC';
$iv = substr(hash('sha256', 'static-iv-for-single-file-prototype'), 0, 16);

// Database Helpers
function load_db($filename) {
    if (!file_exists($filename)) return [];
    return json_decode(file_get_contents($filename), true) ?: [];
}
function save_db($filename, $data) {
    file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT));
}
function log_deletion($data) {
    $log_file = 'deletion_log.jsonl';
    file_put_contents(
        $log_file,
        json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

// 1. Authentication and User Mapping
$db_users = 'users.json';
$users_data = load_db($db_users);
if (empty($users_data)) $users_data = ['next_id' => 0, 'map' => [], 'reverse_map' => []];

$cookie_name = 'app_session';
$user_id = null;
$encrypted_uuid = null;

if (!isset($_COOKIE[$cookie_name])) {
    $uuid = bin2hex(random_bytes(16));
    $encrypted_uuid = openssl_encrypt($uuid, $cipher, $app_key, 0, $iv);
    
    $user_id = $users_data['next_id']++;
    $users_data['map'][$uuid] = $user_id;
    $users_data['reverse_map'][$user_id] = $encrypted_uuid;
    save_db($db_users, $users_data);
    
    setcookie($cookie_name, $encrypted_uuid, time() + (10 * 365 * 24 * 60 * 60), '/'); // 10 years
    $redirect_url = 'index.php' . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']);
    header("Location: $redirect_url");
    exit;
} else {
    $encrypted_uuid = $_COOKIE[$cookie_name];
    $uuid = openssl_decrypt($encrypted_uuid, $cipher, $app_key, 0, $iv);
    if ($uuid && isset($users_data['map'][$uuid])) {
        $user_id = $users_data['map'][$uuid];
    } else {
        setcookie($cookie_name, '', time() - 3600, '/');
        header("Location: index.php");
        exit;
    }
}

// API Endpoint for Autocomplete
if (isset($_GET['api']) && $_GET['api'] === 'local_items') {
    header('Content-Type: application/json');
    $items = load_db('data_items.json');
    $query = strtolower($_GET['q'] ?? '');
    $results = [];
    foreach ($items as $id => $data) {
        if (strpos(strtolower($data['label']), $query) !== false) {
            $results[] = ['id' => $id, 'label' => $data['label'] . ' (Local)'];
        }
    }
    echo json_encode($results);
    exit;
}

// 2. Handle Management
$db_handles = 'handles.json';
$handles_data = load_db($db_handles);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'change_handle') {
        $handles_data[$user_id] = trim($_POST['handle_name']);
        save_db($db_handles, $handles_data);
        header("Location: index.php?page=profile");
        exit;
    }
    // 3. Forum Actions
    if ($_POST['action'] === 'new_thread') {
        $db_forum = 'forum.json';
        $forum = load_db($db_forum);
        $thread_id = uniqid('t_');
        $forum[$thread_id] = [
            'id' => $thread_id,
            'title' => $_POST['title'],
            'posts' => [[
                'author' => $user_id,
                'content' => $_POST['content'],
                'time' => time()
            ]]
        ];
        save_db($db_forum, $forum);
        header("Location: index.php?page=forum");
        exit;
    }
    if ($_POST['action'] === 'reply_thread') {
        $db_forum = 'forum.json';
        $forum = load_db($db_forum);
        $t_id = $_POST['thread_id'];
        if (isset($forum[$t_id])) {
            $forum[$t_id]['posts'][] = [
                'author' => $user_id,
                'content' => $_POST['content'],
                'time' => time()
            ];
            save_db($db_forum, $forum);
        }
        header("Location: index.php?page=thread&id=$t_id");
        exit;
    }
    
    // Blog Actions
    if ($_POST['action'] === 'new_blog') {
        $db_blogs = 'blogs.json';
        $blogs = load_db($db_blogs);
        $blog_id = uniqid('b_');
        $blogs[$blog_id] = [
            'id' => $blog_id,
            'author' => $user_id,
            'title' => $_POST['title'],
            'posts' => [[
                'author' => $user_id,
                'content' => $_POST['content'],
                'time' => time()
            ]]
        ];
        save_db($db_blogs, $blogs);
        header("Location: index.php?page=profile");
        exit;
    }
    if ($_POST['action'] === 'reply_blog') {
        $db_blogs = 'blogs.json';
        $blogs = load_db($db_blogs);
        $b_id = $_POST['blog_id'];
        if (isset($blogs[$b_id])) {
            $blogs[$b_id]['posts'][] = [
                'author' => $user_id,
                'content' => $_POST['content'],
                'time' => time()
            ];
            save_db($db_blogs, $blogs);
        }
        header("Location: index.php?page=blogpost&id=$b_id");
        exit;
    }

    // 4. Data Actions
    if ($_POST['action'] === 'new_item') {
        $db_items = 'data_items.json';
        $items = load_db($db_items);
        $new_id = 'L' . (count($items) + 1);
        $items[$new_id] = ['label' => $_POST['label'], 'desc' => $_POST['desc']];
        save_db($db_items, $items);
        header("Location: index.php?page=data");
        exit;
    }
    if ($_POST['action'] === 'new_prop') {
        $db_props = 'data_props.json';
        $props = load_db($db_props);
        $new_id = 'P' . (count($props) + 1);
        $props[$new_id] = ['label' => $_POST['label']];
        save_db($db_props, $props);
        header("Location: index.php?page=data");
        exit;
    }
    if ($_POST['action'] === 'add_statement') {
        $db_statements = 'data_statements.json';
        $statements = load_db($db_statements);
        $item_id = $_POST['item_id'];
        if (!isset($statements[$item_id])) $statements[$item_id] = [];
        $statements[$item_id][] = [
            'property' => $_POST['property_id'],
            'value_id' => $_POST['value_id'],
            'value_label' => $_POST['value_label']
        ];
        save_db($db_statements, $statements);
        header("Location: index.php?page=item&id=$item_id");
        exit;
    }
    if ($_POST['action'] === 'move_statement') {
        $db_statements = 'data_statements.json';
        $statements = load_db($db_statements);
        $item_id = $_POST['item_id'];
        $stmt_idx = (int)$_POST['statement_index'];
        $dir = $_POST['dir'];
        $target_idx = $dir === 'up' ? $stmt_idx - 1 : $stmt_idx + 1;
        
        if (isset($statements[$item_id][$stmt_idx]) && isset($statements[$item_id][$target_idx])) {
            $temp = $statements[$item_id][$stmt_idx];
            $statements[$item_id][$stmt_idx] = $statements[$item_id][$target_idx];
            $statements[$item_id][$target_idx] = $temp;
            save_db($db_statements, $statements);
        }
        header("Location: index.php?page=item&id=$item_id");
        exit;
    }
    if ($_POST['action'] === 'delete_statement') {
        $db_statements = 'data_statements.json';
        $statements = load_db($db_statements);
        $item_id = $_POST['item_id'];
        $stmt_idx = (int)$_POST['statement_index'];

        if (isset($statements[$item_id][$stmt_idx])) {
            $deleted_stmt = $statements[$item_id][$stmt_idx];
            $items = load_db('data_items.json');
            $props = load_db('data_props.json');

            log_deletion([
                'time' => date('c'),
                'unix_time' => time(),
                'user_id' => $user_id,
                'user_handle' => get_handle($user_id),
                'triple' => [
                    'item_id' => $item_id,
                    'item_label' => $items[$item_id]['label'] ?? $item_id,
                    'property_id' => $deleted_stmt['property'],
                    'property_label' => $props[$deleted_stmt['property']]['label'] ?? $deleted_stmt['property'],
                    'value_id' => $deleted_stmt['value_id'],
                    'value_label' => $deleted_stmt['value_label']
                ]
            ]);

            array_splice($statements[$item_id], $stmt_idx, 1);
            save_db($db_statements, $statements);
        }
        header("Location: index.php?page=item&id=$item_id");
        exit;
    }
    if ($_POST['action'] === 'edit_item') {
        $db_items = 'data_items.json';
        $items = load_db($db_items);
        $item_id = $_POST['item_id'];
        if (isset($items[$item_id])) {
            $items[$item_id]['label'] = $_POST['label'];
            $items[$item_id]['desc'] = $_POST['desc'];
            save_db($db_items, $items);
        }
        header("Location: index.php?page=item&id=$item_id");
        exit;
    }
    if ($_POST['action'] === 'move_list') {
        $db_lists = 'lists.json';
        $lists = load_db($db_lists);
        $list_idx = (int)$_POST['list_index'];
        $dir = $_POST['dir'];
        $target_idx = $dir === 'up' ? $list_idx - 1 : $list_idx + 1;
        
        if (isset($lists[$user_id][$list_idx]) && isset($lists[$user_id][$target_idx])) {
            $temp = $lists[$user_id][$list_idx];
            $lists[$user_id][$list_idx] = $lists[$user_id][$target_idx];
            $lists[$user_id][$target_idx] = $temp;
            save_db($db_lists, $lists);
        }
        header("Location: index.php?page=profile");
        exit;
    }
    if ($_POST['action'] === 'edit_list') {
        $db_lists = 'lists.json';
        $lists = load_db($db_lists);
        $list_idx = (int)$_POST['list_index'];
        if (isset($lists[$user_id][$list_idx])) {
            $lists[$user_id][$list_idx]['title'] = $_POST['title'];
            $lists[$user_id][$list_idx]['desc'] = $_POST['desc'];
            save_db($db_lists, $lists);
        }
        header("Location: index.php?page=profile");
        exit;
    }
    if ($_POST['action'] === 'delete_list') {
        $db_lists = 'lists.json';
        $lists = load_db($db_lists);
        $list_idx = (int)$_POST['list_index'];
        if (isset($lists[$user_id][$list_idx])) {
            array_splice($lists[$user_id], $list_idx, 1);
            save_db($db_lists, $lists);
        }
        header("Location: index.php?page=profile");
        exit;
    }
    // 5. Lists Actions
    if ($_POST['action'] === 'create_list') {
        $db_lists = 'lists.json';
        $lists = load_db($db_lists);
        if (!isset($lists[$user_id])) $lists[$user_id] = [];
        $lists[$user_id][] = [
            'id' => uniqid('list_'),
            'title' => $_POST['title'],
            'desc' => $_POST['desc'],
            'items' => []
        ];
        save_db($db_lists, $lists);
        header("Location: index.php?page=profile");
        exit;
    }
    if ($_POST['action'] === 'add_to_list') {
        $db_lists = 'lists.json';
        $lists = load_db($db_lists);
        $list_idx = $_POST['list_index'];
        if (isset($lists[$user_id][$list_idx])) {
            $lists[$user_id][$list_idx]['items'][] = [
                'item_id' => $_POST['item_id'],
                'item_label' => $_POST['item_label'],
                'comment' => $_POST['comment']
            ];
            save_db($db_lists, $lists);
        }
        header("Location: index.php?page=list&idx=$list_idx");
        exit;
    }
    if ($_POST['action'] === 'move_list_item') {
        $db_lists = 'lists.json';
        $lists = load_db($db_lists);
        $list_idx = $_POST['list_index'];
        $item_idx = (int)$_POST['item_index'];
        $dir = $_POST['dir'];
        $target_idx = $dir === 'up' ? $item_idx - 1 : $item_idx + 1;
        
        $items = &$lists[$user_id][$list_idx]['items'];
        if (isset($items[$item_idx]) && isset($items[$target_idx])) {
            $temp = $items[$item_idx];
            $items[$item_idx] = $items[$target_idx];
            $items[$target_idx] = $temp;
            save_db($db_lists, $lists);
        }
        header("Location: index.php?page=list&idx=$list_idx");
        exit;
    }
    if ($_POST['action'] === 'edit_list_item') {
        $db_lists = 'lists.json';
        $lists = load_db($db_lists);
        $list_idx = $_POST['list_index'];
        $item_idx = (int)$_POST['item_index'];
        if (isset($lists[$user_id][$list_idx]['items'][$item_idx])) {
            $lists[$user_id][$list_idx]['items'][$item_idx]['comment'] = $_POST['comment'];
            save_db($db_lists, $lists);
        }
        header("Location: index.php?page=list&idx=$list_idx");
        exit;
    }
    if ($_POST['action'] === 'delete_list_item') {
        $db_lists = 'lists.json';
        $lists = load_db($db_lists);
        $list_idx = $_POST['list_index'];
        $item_idx = (int)$_POST['item_index'];
        if (isset($lists[$user_id][$list_idx]['items'][$item_idx])) {
            array_splice($lists[$user_id][$list_idx]['items'], $item_idx, 1);
            save_db($db_lists, $lists);
        }
        header("Location: index.php?page=list&idx=$list_idx");
        exit;
    }
}

function get_handle($id) {
    global $handles_data;
    return isset($handles_data[$id]) ? htmlspecialchars($handles_data[$id]) : "Anonymous_$id";
}
function get_enc_uuid($id) {
    global $users_data;
    return $users_data['reverse_map'][$id] ?? 'unknown';
}

function get_value_link($value_id, $value_label) {
    if (strpos($value_id, 'Q') === 0) {
        return '<a href="https://www.wikidata.org/wiki/' . htmlspecialchars($value_id) . '" target="_blank">' . htmlspecialchars($value_label) . '</a>';
    } elseif (strpos($value_id, 'L') === 0) {
        return '<a href="?page=item&id=' . urlencode($value_id) . '">' . htmlspecialchars($value_label) . '</a>';
    } elseif (filter_var($value_id, FILTER_VALIDATE_URL)) {
        return '<a href="' . htmlspecialchars($value_id) . '" target="_blank">' . htmlspecialchars($value_label) . '</a>';
    } elseif (filter_var($value_label, FILTER_VALIDATE_URL)) {
        return '<a href="' . htmlspecialchars($value_label) . '" target="_blank">' . htmlspecialchars($value_label) . '</a>';
    } else {
        return htmlspecialchars($value_label);
    }
}

$page = $_GET['page'] ?? 'forum';


// Define default metadata settings for fallback
$page_title = "BisikBekasi.rf.gd";
$page_desc = "BisikBekasi forum, blogs and knowledge base.";
$page_url = "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

// Check if the current request is for a specific blog post entry
if (isset($_GET['page']) && $_GET['page'] === 'blogpost' && isset($_GET['id'])) {
    $blog_id = $_GET['id'];
    $all_blogs = load_db('blogs.json');
    if (isset($all_blogs[$blog_id])) {
        $target_blog = $all_blogs[$blog_id];
        $page_title = htmlspecialchars($target_blog['title']) . " - BisikBekasi";
        if (!empty($target_blog['posts'])) {
            $first_post_content = $target_blog['posts'][0]['content'];
            $page_desc = htmlspecialchars(mb_strimwidth(strip_tags($first_post_content), 0, 150, "..."));
        }
    }
}


?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>

    <meta property="og:type" content="article">
    <meta property="og:title" content="<?php echo $page_title; ?>">
    <meta property="og:description" content="<?php echo $page_desc; ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($page_url); ?>">
    <meta property="og:image" content="https://pbs.twimg.com/profile_images/1716831335724326912/8ujZJHcJ_400x400.jpg">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo $page_title; ?>">
    <meta name="twitter:description" content="<?php echo $page_desc; ?>">
    <meta name="twitter:image" content="https://pbs.twimg.com/profile_images/1716831335724326912/8ujZJHcJ_400x400.jpg">


    
    <link rel="icon" href="https://pbs.twimg.com/profile_images/1716831335724326912/8ujZJHcJ_400x400.jpg" type="image/x-icon" />
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
@font-face {
    font-family: 'Noto';
    font-style: normal;
    font-weight: normal;
    src: url('noto.woff2') format('woff');
}



        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5; 
            color: #333;
            line-height: 1.5;
        }

        img {
                display: block;
    margin-left: auto;
    margin-right: auto;
max-width: 100%;
    height: auto;
        }
        
        .container { 
            max-width: 100%;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        header { 
            background: white;
            border-bottom: 1px solid #e0e0e0;
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        
        header > div:first-child {
            font-size: 13px;
            color: #666;
            white-space: nowrap;
        }
        
        header strong {
            color: #333;
            font-weight: 600;
        }
        
        nav {
            display: flex;
            gap: 8px;
        }
        
        nav a {
            padding: 8px 12px;
            text-decoration: none;
            color: #0066cc;
            font-size: 14px;
            font-weight: 500;
            border-radius: 4px;
            transition: background 0.2s;
        }
        
        nav a:hover {
            background: #f0f0f0;
        }
        
        main {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
        }
        
        h2 {
            font-size: 20px;
            margin-bottom: 12px;
            color: #222;
        }
        
        h3 {
            font-size: 16px;
            margin-bottom: 10px;
            color: #333;
        }
        
        h4 {
            font-size: 14px;
            margin-bottom: 8px;
            color: #555;
        }
        
        .box {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 12px;
            transition: box-shadow 0.2s;
        }
        
        .box:hover {
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }
        
        input[type="text"],
        input[type="email"],
        textarea,
        select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: inherit;
            font-size: 14px;
            margin-bottom: 8px;
            transition: border-color 0.2s;
        }
        
        input[type="text"]:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: #0066cc;
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
        }

        #prop_dropdown_btn {
            color: #333;
            background: white;
            cursor: pointer;
            transition: border-color 0.2s;
        }

        #prop_dropdown_btn:hover {
            border-color: #999;
        }

        #prop_dropdown_btn:focus {
            outline: none;
            border-color: #0066cc;
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
        }
        
        textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        button {
            padding: 8px 14px;
            background: #0066cc;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        button:hover {
            background: #0052a3;
        }
        
        button:active {
            transform: scale(0.98);
        }
        
        .handle {
            color: #d9534f;
            font-weight: 600;
            cursor: pointer;
            transition: color 0.2s;
            text-decoration: none;
        }
        
        .handle:hover {
            color: #c9302c;
            text-decoration: underline;
        }
        
        .autocomplete-results {
            border: 1px solid #ddd;
            border-radius: 4px;
            max-height: 200px;
            overflow-y: auto;
            background: white;
            position: absolute;
            min-width: 250px;
            z-index: 100;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .autocomplete-results div {
            padding: 8px;
            font-size: 13px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .autocomplete-results div:last-child {
            border-bottom: none;
        }
        
        .autocomplete-results div:hover {
            background: #f9f9f9;
        }
        
        .forum-thread {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            margin-bottom: 10px;
            overflow: hidden;
            transition: all 0.2s;
        }
        
        .forum-thread:hover {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .forum-thread-header {
            padding: 10px 12px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 8px;
        }
        
        .forum-thread-title {
            font-weight: 600;
            color: #0066cc;
            text-decoration: none;
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .forum-thread-title:hover {
            text-decoration: underline;
        }
        
        .forum-thread-meta {
            font-size: 12px;
            color: #999;
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        .post {
            padding: 10px 12px;
            border-bottom: 1px solid #f5f5f5;
        }
        
        .post:last-child {
            border-bottom: none;
        }
        
        .post-header {
            font-size: 13px;
            margin-bottom: 6px;
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .post-content {
            /*
            font-size: 14px;
            color: #333;
            word-break: break-word;
            */
                width: 95%;
    border: 0px;
    overflow: hidden;
    resize: none;
    font-family: 'Noto';
    color: #1A1A1B;
    font-size: 14px !important;
    line-height: 21px;
    font-weight: 400;
    /*padding-bottom: 15px;*/
    margin-top: 47px;

    
    margin: 0px auto 0px auto;
    max-width: 750px;
    
        }

.post-content blockquote {
    border-left: 1px solid #cacaca;
    padding-left: 9px;
    margin-left: 19px;
    margin-right: 7px;
}

        .post-content blockquote p {
    padding-top: 4px;
    padding-bottom: 4px;
}

        .post-content h1 {

    text-align: center;
    margin-bottom: 44px;
    line-height: 38px;

        }

.post-content h3 {
    display: block;
    font-size: 1.17em;
    margin-block-start: 1em;
    margin-block-end: 1em;
    margin-inline-start: 0px;
    margin-inline-end: 0px;
    font-weight: bold;
    unicode-bidi: isolate;
}

.post-content p {

    margin: 19px 0px;
    display: block;
    margin-block-start: 1em;
    margin-block-end: 1em;
    margin-inline-start: 0px;
    margin-inline-end: 0px;
    unicode-bidi: isolate;

}


        .post-content ol {
                display: block;
    list-style-type: decimal;
    margin-block-start: 1em;
    margin-block-end: 1em;
    padding-inline-start: 40px;
    unicode-bidi: isolate;
        }

        .post-content ul {
                display: block;
    list-style-type: disc;
    margin-block-start: 1em;
    margin-block-end: 1em;
    padding-inline-start: 40px;
    unicode-bidi: isolate;
        }


        .post-content hr{
                width: 100%;
    border: #eee 1px solid;
    margin: 39px 0px;
        }

        .post-content p {
            margin: 0 0 10px 0;
        }
        .post-content pre {
            background: #f4f4f4; padding: 10px; border-radius: 4px; overflow-x: auto;
        }
        .post-content code {
            font-family: monospace; background: #eee; padding: 2px 4px; border-radius: 3px;
        }
        
        .list-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px;
            border-bottom: 1px solid #f0f0f0;
            gap: 8px;
            font-size: 14px;
        }
        
        .list-item:last-child {
            border-bottom: none;
        }
        
        .list-item-left {
            flex: 1;
            min-width: 0;
        }
        
        .list-item-label {
            font-weight: 500;
            color: #333;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .list-item-comment {
            font-size: 12px;
            color: #999;
            margin-top: 2px;
        }
        
        .list-item-controls {
            display: flex;
            gap: 4px;
            flex-shrink: 0;
        }
        
        .list-item-controls button {
            padding: 4px 6px;
            font-size: 12px;
        }
        
        .data-table {
            width: 100%;
            font-size: 13px;
            border-collapse: collapse;
        }
        
        .data-table th {
            background: #f9f9f9;
            padding: 8px;
            text-align: left;
            font-weight: 600;
            border-bottom: 1px solid #ddd;
        }
        
        .data-table td {
            padding: 8px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .data-item {
            padding: 8px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
        }
        
        .data-item:last-child {
            border-bottom: none;
        }
        
        .data-item-link {
            color: #0066cc;
            text-decoration: none;
            font-weight: 500;
        }
        
        .data-item-link:hover {
            text-decoration: underline;
        }
        
        .data-item-desc {
            color: #999;
            font-size: 12px;
        }
        
        .statement-item {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 10px;
            border-bottom: 1px solid #f0f0f0;
            gap: 10px;
        }
        
        .statement-item:last-child {
            border-bottom: none;
        }
        
        .statement-content {
            flex: 1;
            min-width: 0;
        }
        
        .statement-property {
            font-size: 13px;
            font-weight: 500;
            color: #666;
            margin-bottom: 4px;
        }
        
        .statement-value {
            font-size: 14px;
            color: #333;
            word-break: break-word;
        }
        
        .statement-value a {
            color: #0066cc;
            text-decoration: none;
        }
        
        .statement-value a:hover {
            text-decoration: underline;
        }
        
        .statement-controls {
            display: flex;
            gap: 4px;
            flex-shrink: 0;
        }
        
        .statement-controls button {
            padding: 4px 6px;
            font-size: 12px;
        }
        
        .form-section {
            margin-bottom: 12px;
        }
        
        .section-divider {
            height: 1px;
            background: #e0e0e0;
            margin: 12px 0;
        }
        
        /* Responsive Design */
        @media (max-width: 640px) {
            body {
                padding: 0;
            }
            
            header {
                padding: 10px;
                flex-direction: column;
                align-items: flex-start;
            }
            
            header > div:first-child {
                width: 100%;
                font-size: 12px;
            }
            
            nav {
                width: 100%;
                gap: 4px;
            }
            
            nav a {
                flex: 1;
                text-align: center;
                padding: 10px 8px;
                font-size: 12px;
            }
            
            main {
                padding: 10px;
            }
            
            .grid-2 {
                grid-template-columns: 1fr;
            }
            
            .box {
                padding: 10px;
                margin-bottom: 10px;
            }
            
            h2 {
                font-size: 18px;
            }
            
            h3 {
                font-size: 14px;
            }
            
            .forum-thread-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .forum-thread-meta {
                width: 100%;
                white-space: normal;
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div>
                <strong>User:</strong> <?php echo get_handle($user_id); ?> <strong>|</strong> ID: <?php echo $user_id; ?>
            </div>
            <nav>
                <a href="?page=forum">Forum</a>
                <a href="?page=blogs">Blogs</a>
                <a href="?page=data">KnowledgeBase</a>
                <a href="?page=profile">Profile</a>
            </nav>
        </header>

        <main>
        <?php if ($page === 'profile'): ?>
            <h2>Profile</h2>
            <div class="box">
                <h3>Handle</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="change_handle">
                    <div style="display: flex; gap: 8px;">
                        <input type="text" name="handle_name" value="<?php echo get_handle($user_id); ?>" required style="flex: 1;">
                        <button type="submit">Update</button>
                    </div>
                </form>
            </div>

            <div class="box">
                <h3>My Blogs</h3>
                <div style="margin-bottom: 12px;">
                    <button onclick="document.getElementById('newBlogForm').style.display = document.getElementById('newBlogForm').style.display === 'none' ? 'block' : 'none'">+ Create New Blog Post</button>
                </div>
                <div id="newBlogForm" style="display: none; background: #f9f9f9; padding: 10px; margin-bottom: 10px; border-radius: 4px; border-left: 3px solid #0066cc;">
                    <form method="POST">
                        <input type="hidden" name="action" value="new_blog">
                        <input type="text" name="title" placeholder="Blog Title" required style="margin-bottom: 8px;">
                        <textarea name="content" placeholder="Write your blog post (Markdown supported)..." required style="font-size: 13px; margin-bottom: 8px; height: 100px;"></textarea>
                        <div style="display: flex; gap: 8px;">
                            <button type="submit" style="font-size: 12px; padding: 6px 10px;">Post</button>
                            <button type="button" onclick="document.getElementById('newBlogForm').style.display = 'none'" style="background: #6c757d; font-size: 12px; padding: 6px 10px;">Cancel</button>
                        </div>
                    </form>
                </div>
                
                <?php 
                $blogs = load_db('blogs.json');
                $my_blogs = array_filter($blogs, function($b) use ($user_id) { return $b['author'] === $user_id; });
                if (empty($my_blogs)): ?>
                    <p style="font-size: 13px; color: #999;">You have not posted any blogs yet.</p>
                <?php else:
                    foreach (array_reverse($my_blogs) as $blog): ?>
                        <div class="data-item">
                            <a href="?page=blogpost&id=<?php echo htmlspecialchars($blog['id']); ?>" class="data-item-link"><?php echo htmlspecialchars($blog['title']); ?></a>
                            <div class="data-item-desc"><?php echo count($blog['posts'])-1; ?> comments • <?php echo date('M j, H:i', $blog['posts'][0]['time']); ?></div>
                        </div>
                    <?php endforeach;
                endif; ?>
            </div>

            <div class="box">
                <h3>My Lists (<?php echo count(load_db('lists.json')[$user_id] ?? []); ?>)</h3>
                <?php 
                $lists = load_db('lists.json')[$user_id] ?? [];
                if (empty($lists)): ?>
                    <p style="font-size: 13px; color: #999;">No lists yet. Create one below.</p>
                <?php else: ?>
                    <?php foreach ($lists as $idx => $list): ?>
                        <div class="list-item">
                            <div class="list-item-left">
                                <a href="?page=list&idx=<?php echo htmlspecialchars($idx); ?>" class="data-item-link" style="display: block; margin-bottom: 4px;"><?php echo htmlspecialchars($list['title']); ?></a>
                                <div class="list-item-comment"><?php echo htmlspecialchars($list['desc']); ?> • <?php echo count($list['items']); ?> items</div>
                            </div>
                            <div class="list-item-controls">
                                <form method="POST" style="display:contents;">
                                    <input type="hidden" name="action" value="move_list">
                                    <input type="hidden" name="list_index" value="<?php echo htmlspecialchars($idx); ?>">
                                    <button name="dir" value="up">↑</button>
                                    <button name="dir" value="down">↓</button>
                                </form>
                                <button onclick="document.getElementById('editListForm_<?php echo $idx; ?>').style.display = document.getElementById('editListForm_<?php echo $idx; ?>').style.display === 'none' ? 'block' : 'none'" style="background: #28a745;">✎</button>
                                <form method="POST" style="display:inline; margin: 0;">
                                    <input type="hidden" name="action" value="delete_list">
                                    <input type="hidden" name="list_index" value="<?php echo htmlspecialchars($idx); ?>">
                                    <button style="background: #dc3545;" onclick="return confirm('Delete this list?');">✕</button>
                                </form>
                            </div>
                        </div>
                        <div id="editListForm_<?php echo $idx; ?>" style="display: none; background: #f9f9f9; padding: 10px; margin-bottom: 10px; border-radius: 4px; border-left: 3px solid #28a745;">
                            <form method="POST">
                                <input type="hidden" name="action" value="edit_list">
                                <input type="hidden" name="list_index" value="<?php echo htmlspecialchars($idx); ?>">
                                <input type="text" name="title" value="<?php echo htmlspecialchars($list['title']); ?>" placeholder="List title" required style="margin-bottom: 8px;">
                                <textarea name="desc" style="font-size: 13px; margin-bottom: 8px; height: 60px;" placeholder="Edit description..."><?php echo htmlspecialchars($list['desc']); ?></textarea>
                                <div style="display: flex; gap: 8px;">
                                    <button type="submit" style="font-size: 12px; padding: 6px 10px;">Save</button>
                                    <button type="button" onclick="document.getElementById('editListForm_<?php echo $idx; ?>').style.display = 'none'" style="background: #6c757d; font-size: 12px; padding: 6px 10px;">Cancel</button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <div class="section-divider"></div>
                <h4>Create List</h4>
                <form method="POST">
                    <input type="hidden" name="action" value="create_list">
                    <input type="text" name="title" placeholder="List Title" required>
                    <input type="text" name="desc" placeholder="Description" style="margin-bottom: 0;">
                    <div style="margin-top: 8px;">
                        <button type="submit">Create</button>
                    </div>
                </form>
            </div>

        <?php elseif ($page === 'list'): ?>
            <?php 
            $idx = (int)$_GET['idx'];
            $list = load_db('lists.json')[$user_id][$idx] ?? null;
            if (!$list) die("List not found.");
            ?>
            <h2><?php echo htmlspecialchars($list['title']); ?></h2>
            <p style="color: #666; font-size: 14px; margin-bottom: 12px;"><?php echo htmlspecialchars($list['desc']); ?></p>
            
            <div class="box">
                <h3>Items (<?php echo count($list['items']); ?>)</h3>
                <?php if (empty($list['items'])): ?>
                    <p style="font-size: 13px; color: #999;">No items yet. Add one below.</p>
                <?php else: ?>
                    <?php foreach ($list['items'] as $item_idx => $item): ?>
                        <div class="list-item">
                            <div class="list-item-left">
                                <a href="?page=item&id=<?php echo urlencode($item['item_id']); ?>" class="data-item-link" style="display: block; margin-bottom: 4px;"><?php echo $item['item_label']; ?></a>
                                <?php if ($item['comment']): ?>
                                    <div class="list-item-comment"><?php echo $item['comment']; ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="list-item-controls">
                                <form method="POST" style="display:contents;">
                                    <input type="hidden" name="action" value="move_list_item">
                                    <input type="hidden" name="list_index" value="<?php echo $idx; ?>">
                                    <input type="hidden" name="item_index" value="<?php echo $item_idx; ?>">
                                    <button name="dir" value="up">↑</button>
                                    <button name="dir" value="down">↓</button>
                                </form>
                                <button onclick="document.getElementById('editForm_<?php echo $item_idx; ?>').style.display = document.getElementById('editForm_<?php echo $item_idx; ?>').style.display === 'none' ? 'block' : 'none'" style="background: #28a745;">✎</button>
                                <form method="POST" style="display:inline; margin: 0;">
                                    <input type="hidden" name="action" value="delete_list_item">
                                    <input type="hidden" name="list_index" value="<?php echo $idx; ?>">
                                    <input type="hidden" name="item_index" value="<?php echo $item_idx; ?>">
                                    <button style="background: #dc3545;" onclick="return confirm('Delete this item from list?');">✕</button>
                                </form>
                            </div>
                        </div>
                        <div id="editForm_<?php echo $item_idx; ?>" style="display: none; background: #f9f9f9; padding: 10px; margin-bottom: 10px; border-radius: 4px; border-left: 3px solid #28a745;">
                            <form method="POST">
                                <input type="hidden" name="action" value="edit_list_item">
                                <input type="hidden" name="list_index" value="<?php echo $idx; ?>">
                                <input type="hidden" name="item_index" value="<?php echo $item_idx; ?>">
                                <textarea name="comment" style="font-size: 13px; margin-bottom: 8px; height: 60px;" placeholder="Edit comment..."><?php echo htmlspecialchars($item['comment']); ?></textarea>
                                <div style="display: flex; gap: 8px;">
                                    <button type="submit" style="font-size: 12px; padding: 6px 10px;">Save</button>
                                    <button type="button" onclick="document.getElementById('editForm_<?php echo $item_idx; ?>').style.display = 'none'" style="background: #6c757d; font-size: 12px; padding: 6px 10px;">Cancel</button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="box">
                <h3>Add Item</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="add_to_list">
                    <input type="hidden" name="list_index" value="<?php echo $idx; ?>">
                    <input type="hidden" name="item_id" id="list_item_id">
                    <div style="position: relative;">
                        <input type="text" name="item_label" id="list_item_search" placeholder="Search item..." autocomplete="off">
                        <div id="list_autocomplete" class="autocomplete-results"></div>
                    </div>
                    <input type="text" name="comment" placeholder="Comment (optional)" style="margin-bottom: 0;">
                    <div style="margin-top: 8px;">
                        <button type="submit">Add</button>
                    </div>
                </form>
            </div>

        <?php elseif ($page === 'forum'): ?>
            <?php
            $forum = load_db('forum.json');
            $sort = $_GET['sort'] ?? 'bump';
            
            uasort($forum, function($a, $b) use ($sort) {
                if ($sort === 'latest') return $b['posts'][0]['time'] <=> $a['posts'][0]['time'];
                if ($sort === 'replies') return count($b['posts']) <=> count($a['posts']);
                return end($b['posts'])['time'] <=> end($a['posts'])['time'];
            });
            
            $f_page = max(1, (int)($_GET['f_page'] ?? 1));
            $per_page = 50;
            $total_threads = count($forum);
            $forum_slice = array_slice($forum, ($f_page - 1) * $per_page, $per_page, true);
            ?>
            <div style="margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <button id="newThreadBtn" onclick="document.getElementById('newThreadForm').style.display = document.getElementById('newThreadForm').style.display === 'none' ? 'block' : 'none'">+ Create New Thread</button>
                <div style="display: flex; gap: 8px;">
                    <span style="font-size: 13px; padding: 8px 0; color: #666;">Sort:</span>
                    <a href="?page=forum&sort=bump" style="padding: 6px 10px; font-size: 13px; background: <?php echo $sort==='bump'?'#0066cc':'#eee'; ?>; color: <?php echo $sort==='bump'?'white':'#333'; ?>; text-decoration: none; border-radius: 4px;">Bump</a>
                    <a href="?page=forum&sort=latest" style="padding: 6px 10px; font-size: 13px; background: <?php echo $sort==='latest'?'#0066cc':'#eee'; ?>; color: <?php echo $sort==='latest'?'white':'#333'; ?>; text-decoration: none; border-radius: 4px;">Latest</a>
                    <a href="?page=forum&sort=replies" style="padding: 6px 10px; font-size: 13px; background: <?php echo $sort==='replies'?'#0066cc':'#eee'; ?>; color: <?php echo $sort==='replies'?'white':'#333'; ?>; text-decoration: none; border-radius: 4px;">Replies</a>
                </div>
            </div>

            <div id="newThreadForm" class="box" style="margin-bottom: 16px; display: none;">
                <h3>New Thread</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="new_thread">
                    <input type="text" name="title" placeholder="Thread Title" required>
                    <textarea name="content" placeholder="First post (Markdown supported)..." required></textarea>
                    <div style="display: flex; gap: 8px;">
                        <button type="submit">Post</button>
                        <button type="button" onclick="document.getElementById('newThreadForm').style.display = 'none'" style="background: #6c757d;">Cancel</button>
                    </div>
                </form>
            </div>

            <h3 style="margin-bottom: 10px; margin-top: 0;">Threads</h3>
            <?php if (empty($forum_slice)): ?>
                <p style="text-align: center; color: #999; font-size: 14px; padding: 20px;">No threads yet. Be the first to create one.</p>
            <?php else:
                foreach ($forum_slice as $thread): ?>
                    <div class="forum-thread">
                        <div class="forum-thread-header">
                            <a href="?page=thread&id=<?php echo htmlspecialchars($thread['id']); ?>" class="forum-thread-title"><?php echo htmlspecialchars($thread['title']); ?></a>
                            <span class="forum-thread-meta"><?php echo count($thread['posts']); ?> posts • <?php echo date('M j, H:i', end($thread['posts'])['time']); ?></span>
                        </div>
                    </div>
                <?php endforeach;
                if ($total_threads > $f_page * $per_page): ?>
                    <div style="margin-top: 12px; text-align: center;">
                        <a href="?page=forum&sort=<?php echo htmlspecialchars($sort); ?>&f_page=<?php echo $f_page + 1; ?>" style="padding: 8px 14px; background: #e0e0e0; color: #333; text-decoration: none; border-radius: 4px; font-size: 14px; display: inline-block;">Load More Threads</a>
                    </div>
                <?php endif;
            endif; ?>

        <?php elseif ($page === 'thread'): ?>
            <?php 
            $t_id = $_GET['id'];
            $forum = load_db('forum.json');
            $thread = $forum[$t_id] ?? null;
            if (!$thread) die("Thread not found.");
            ?>
            <h2><?php echo htmlspecialchars($thread['title']); ?></h2>

            <div class="box" style="margin-bottom: 16px;">
                <h3><?php echo count($thread['posts']); ?> Posts</h3>
                <?php foreach ($thread['posts'] as $post): ?>
                    <div class="post">
                        <div class="post-header">
                            <a href="?page=user&id=<?php echo htmlspecialchars($post['author']); ?>" class="handle" title="View profile"><?php echo get_handle($post['author']); ?></a>
                            <span style="color: #999; font-size: 12px;">ID: <?php echo htmlspecialchars($post['author']); ?></span>
                            <span style="color: #999; font-size: 12px;">· <?php echo date('M j, H:i', $post['time']); ?></span>
                        </div>
                        <div class="post-content markdown-renderer" data-markdown="<?php echo htmlspecialchars($post['content'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="box">
                <h3>Reply</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="reply_thread">
                    <input type="hidden" name="thread_id" value="<?php echo htmlspecialchars($t_id); ?>">
                    <textarea name="content" placeholder="Write a reply (Markdown supported)..." required></textarea>
                    <button type="submit">Post Reply</button>
                </form>
            </div>
            
        <?php elseif ($page === 'blogs'): ?>
            <h2 style="margin-bottom: 16px;">Global Blog Feed</h2>
            <div class="box">
            <?php 
            $blogs = load_db('blogs.json');
            uasort($blogs, function($a, $b) {
                return $b['posts'][0]['time'] <=> $a['posts'][0]['time'];
            });
            if (empty($blogs)): ?>
                <p style="text-align: center; color: #999; font-size: 14px; padding: 20px;">No blog posts yet.</p>
            <?php else:
                foreach ($blogs as $blog): ?>
                    <div class="forum-thread">
                        <div class="forum-thread-header">
                            <a href="?page=blogpost&id=<?php echo htmlspecialchars($blog['id']); ?>" class="forum-thread-title"><?php echo htmlspecialchars($blog['title']); ?></a>
                            <span class="forum-thread-meta">by <a href="?page=user&id=<?php echo htmlspecialchars($blog['author']); ?>" style="text-decoration:none; color:inherit;"><?php echo get_handle($blog['author']); ?></a> • <?php echo date('M j, Y', $blog['posts'][0]['time']); ?></span>
                        </div>
                    </div>
                <?php endforeach;
            endif; ?>
            </div>

        <?php elseif ($page === 'blogpost'): ?>
            <?php 
            $b_id = $_GET['id'];
            $blogs = load_db('blogs.json');
            $blog = $blogs[$b_id] ?? null;
            if (!$blog) die("Blog post not found.");
            $original_post = $blog['posts'][0];
            $comments = array_slice($blog['posts'], 1);
            ?>
            <h2><?php echo htmlspecialchars($blog['title']); ?></h2>
            <p style="color: #666; font-size: 13px; margin-bottom: 16px;">Posted by <a href="?page=user&id=<?php echo htmlspecialchars($blog['author']); ?>" class="handle"><?php echo get_handle($blog['author']); ?></a> on <?php echo date('F j, Y, H:i', $original_post['time']); ?></p>

            <div class="box" style="margin-bottom: 16px; background: white; border-top: 3px solid #0066cc;">
                <div class="post-content markdown-renderer" style="font-size: 15px;" data-markdown="<?php echo htmlspecialchars($original_post['content'], ENT_QUOTES, 'UTF-8'); ?>"></div>
            </div>

            <div class="box" style="margin-bottom: 16px;">
                <h3>Comments (<?php echo count($comments); ?>)</h3>
                <?php if (empty($comments)): ?>
                    <p style="font-size: 13px; color: #999;">No comments yet.</p>
                <?php endif; ?>
                <?php foreach ($comments as $post): ?>
                    <div class="post">
                        <div class="post-header">
                            <a href="?page=user&id=<?php echo htmlspecialchars($post['author']); ?>" class="handle"><?php echo get_handle($post['author']); ?></a>
                            <span style="color: #999; font-size: 12px;">· <?php echo date('M j, H:i', $post['time']); ?></span>
                        </div>
                        <div class="post-content markdown-renderer" data-markdown="<?php echo htmlspecialchars($post['content'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="box">
                <h3>Leave a Comment</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="reply_blog">
                    <input type="hidden" name="blog_id" value="<?php echo htmlspecialchars($b_id); ?>">
                    <textarea name="content" placeholder="Write your comment (Markdown supported)..." required></textarea>
                    <button type="submit">Post Comment</button>
                </form>
            </div>

        <?php elseif ($page === 'user'): ?>
            <?php 
            $view_user_id = (int)$_GET['id'];
            $view_user_lists = load_db('lists.json')[$view_user_id] ?? [];
            $view_user_handle = get_handle($view_user_id);
            ?>
            <h2><?php echo $view_user_handle; ?>'s Profile</h2>
            <p style="color: #666; font-size: 13px; margin-bottom: 12px;">User ID: <?php echo $view_user_id; ?></p>
            
            <div class="box">
                <h3>Blogs by <?php echo $view_user_handle; ?></h3>
                <?php 
                $blogs = load_db('blogs.json');
                $user_blogs = array_filter($blogs, function($b) use ($view_user_id) { return $b['author'] === $view_user_id; });
                if (empty($user_blogs)): ?>
                    <p style="font-size: 13px; color: #999;">This user has no blog posts.</p>
                <?php else:
                    foreach (array_reverse($user_blogs) as $blog): ?>
                        <div class="data-item">
                            <a href="?page=blogpost&id=<?php echo htmlspecialchars($blog['id']); ?>" class="data-item-link"><?php echo htmlspecialchars($blog['title']); ?></a>
                            <div class="data-item-desc"><?php echo count($blog['posts'])-1; ?> comments • <?php echo date('M j, Y', $blog['posts'][0]['time']); ?></div>
                        </div>
                    <?php endforeach;
                endif; ?>
            </div>

            <div class="box">
                <h3>Lists (<?php echo count($view_user_lists); ?>)</h3>
                <?php if (empty($view_user_lists)): ?>
                    <p style="font-size: 13px; color: #999;">This user has no lists.</p>
                <?php else: ?>
                    <?php foreach ($view_user_lists as $idx => $list): ?>
                        <div class="data-item">
                            <div class="data-item-left">
                                <a href="?page=viewlist&user=<?php echo htmlspecialchars($view_user_id); ?>&idx=<?php echo htmlspecialchars($idx); ?>" class="data-item-link"><?php echo htmlspecialchars($list['title']); ?></a>
                                <div class="data-item-desc"><?php echo htmlspecialchars($list['desc']); ?> • <?php echo count($list['items']); ?> items</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        <?php elseif ($page === 'viewlist'): ?>
            <?php 
            $view_user_id = (int)$_GET['user'];
            $view_list_idx = (int)$_GET['idx'];
            $view_user_lists = load_db('lists.json')[$view_user_id] ?? [];
            $view_list = $view_user_lists[$view_list_idx] ?? null;
            $view_user_handle = get_handle($view_user_id);
            
            if (!$view_list) die("List not found.");
            ?>
            <h2><?php echo htmlspecialchars($view_user_handle); ?>'s List: <?php echo htmlspecialchars($view_list['title']); ?></h2>
            <p style="color: #666; font-size: 14px; margin-bottom: 12px;"><?php echo htmlspecialchars($view_list['desc']); ?></p>
            <p style="color: #999; font-size: 12px; margin-bottom: 12px;"><a href="?page=user&id=<?php echo htmlspecialchars($view_user_id); ?>">← Back to Profile</a></p>
            
            <div class="box">
                <h3>Items (<?php echo count($view_list['items']); ?>)</h3>
                <?php if (empty($view_list['items'])): ?>
                    <p style="font-size: 13px; color: #999;">This list is empty.</p>
                <?php else: ?>
                    <?php foreach ($view_list['items'] as $item_idx => $item): ?>
                        <div class="list-item">
                            <div class="list-item-left">
                                <a href="?page=item&id=<?php echo urlencode($item['item_id']); ?>" class="data-item-link" style="display: block; margin-bottom: 4px;"><?php echo htmlspecialchars($item['item_label']); ?></a>
                                <?php if ($item['comment']): ?>
                                    <div class="list-item-comment"><?php echo htmlspecialchars($item['comment']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        <?php elseif ($page === 'data'): ?>
            <h2>KnowledgeBase</h2>
            
            <div style="margin-bottom: 16px; display: flex; gap: 8px;">
                <button onclick="document.getElementById('newItemForm').style.display = document.getElementById('newItemForm').style.display === 'none' ? 'block' : 'none'">+ Add New Item</button>
                <a href="?page=properties" style="padding: 8px 14px; background: #0066cc; color: white; text-decoration: none; border-radius: 4px; font-size: 14px; font-weight: 500;">⚙️ Manage Properties</a>
            </div>

            <div id="newItemForm" class="box" style="margin-bottom: 16px; display: none;">
                <h3>New Item</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="new_item">
                    <input type="text" name="label" placeholder="Item (e.g., Earth)" required>
                    <input type="text" name="desc" placeholder="Description" required style="margin-bottom: 0;">
                    <div style="margin-top: 8px; display: flex; gap: 8px;">
                        <button type="submit" style="flex: 1;">Create</button>
                        <button type="button" onclick="document.getElementById('newItemForm').style.display = 'none'" style="background: #6c757d; flex: 1;">Cancel</button>
                    </div>
                </form>
            </div>

            <div class="box">
                <h3>Items Directory (<?php echo count(load_db('data_items.json')); ?>)</h3>
                <?php 
                $all_items = load_db('data_items.json');
                $items_per_page = 10;
                $total_items = count($all_items);
                $page_num = isset($_GET['items_page']) ? (int)$_GET['items_page'] : 1;
                
                $sorted_items = array_reverse($all_items, true);
                $items_slice = array_slice($sorted_items, ($page_num - 1) * $items_per_page, $items_per_page, true);
                $total_pages = ceil($total_items / $items_per_page);
                
                if (empty($all_items)): ?>
                    <p style="font-size: 13px; color: #999;">No items yet.</p>
                <?php else:
                    foreach ($items_slice as $id => $item): ?>
                        <div class="data-item">
                            <a href="?page=item&id=<?php echo urlencode($id); ?>" class="data-item-link"><?php echo htmlspecialchars($item['label']); ?></a>
                            <div class="data-item-desc"><?php echo htmlspecialchars($item['desc']); ?></div>
                        </div>
                    <?php endforeach;
                    
                    if ($total_pages > $page_num): ?>
                        <div style="margin-top: 12px; text-align: center;">
                            <a href="?page=data&items_page=<?php echo $page_num + 1; ?>" style="padding: 8px 14px; background: #f0f0f0; color: #333; text-decoration: none; border-radius: 4px; font-size: 13px;">Load More</a>
                        </div>
                    <?php endif;
                endif; ?>
            </div>

        <?php elseif ($page === 'properties'): ?>
            <h2>Manage Properties</h2>
            <a href="?page=data" style="color: #0066cc; text-decoration: none; font-size: 13px;">← Back to Wiki</a>

            <div class="box" style="margin-top: 12px;">
                <h3>Add New Property</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="new_prop">
                    <input type="text" name="label" placeholder="Property (e.g., Instance of)" required style="margin-bottom: 0;">
                    <div style="margin-top: 8px; display: flex; gap: 8px;">
                        <button type="submit" style="flex: 1;">Create</button>
                    </div>
                </form>
            </div>

            <div class="box">
                <h3>All Properties (<?php echo count(load_db('data_props.json')); ?>)</h3>
                <?php 
                $all_props = load_db('data_props.json');
                $props_per_page = 15;
                $total_props = count($all_props);
                $props_page_num = isset($_GET['props_page']) ? (int)$_GET['props_page'] : 1;
                
                $sorted_props = array_reverse($all_props, true);
                $props_slice = array_slice($sorted_props, ($props_page_num - 1) * $props_per_page, $props_per_page, true);
                $total_props_pages = ceil($total_props / $props_per_page);
                
                if (empty($all_props)): ?>
                    <p style="font-size: 13px; color: #999;">No properties yet.</p>
                <?php else:
                    foreach ($props_slice as $p_id => $prop): ?>
                        <div class="data-item">
                            <div class="data-item-left">
                                <div class="data-item-link" style="display: block;"><?php echo htmlspecialchars($prop['label']); ?></div>
                                <div class="data-item-desc"><?php echo htmlspecialchars($p_id); ?></div>
                            </div>
                        </div>
                    <?php endforeach;
                    
                    if ($total_props_pages > $props_page_num): ?>
                        <div style="margin-top: 12px; text-align: center;">
                            <a href="?page=properties&props_page=<?php echo $props_page_num + 1; ?>" style="padding: 8px 14px; background: #f0f0f0; color: #333; text-decoration: none; border-radius: 4px; font-size: 13px;">Load More</a>
                        </div>
                    <?php endif;
                endif; ?>
            </div>

        <?php elseif ($page === 'item'): ?>
            <?php 
            $i_id = $_GET['id'];
            $items = load_db('data_items.json');
            $props = load_db('data_props.json');
            $statements = load_db('data_statements.json')[$i_id] ?? [];
            $item = $items[$i_id] ?? null;
            if (!$item) die("Item not found.");
            ?>
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 16px;">
                <div>
                    <h2><?php echo htmlspecialchars($item['label']); ?></h2>
                    <p style="color: #666;"><?php echo htmlspecialchars($item['desc']); ?></p>
                </div>
                <button onclick="document.getElementById('editItemForm').style.display = document.getElementById('editItemForm').style.display === 'none' ? 'block' : 'none'" style="background: #28a745; flex-shrink: 0;">✎</button>
            </div>
            
            <div id="editItemForm" class="box" style="margin-bottom: 16px; display: none;">
                <h3>Edit Item</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="edit_item">
                    <input type="hidden" name="item_id" value="<?php echo htmlspecialchars($i_id); ?>">
                    <input type="text" name="label" value="<?php echo htmlspecialchars($item['label']); ?>" placeholder="Item label" required>
                    <textarea name="desc" placeholder="Description" style="min-height: 60px;"><?php echo htmlspecialchars($item['desc']); ?></textarea>
                    <div style="display: flex; gap: 8px;">
                        <button type="submit">Save</button>
                        <button type="button" onclick="document.getElementById('editItemForm').style.display = 'none'" style="background: #6c757d;">Cancel</button>
                    </div>
                </form>
            </div>
            
            <div class="box">
                <h3>Statements (<?php echo count($statements); ?>)</h3>
                <?php if (empty($statements)): ?>
                    <p style="font-size: 13px; color: #999;">No statements yet.</p>
                <?php else: ?>
                    <?php foreach ($statements as $stmt_idx => $st): ?>
                        <div class="statement-item">
                            <div class="statement-content">
                                <div class="statement-property"><?php echo htmlspecialchars($props[$st['property']]['label'] ?? $st['property']); ?></div>
                                <div class="statement-value"><?php echo get_value_link($st['value_id'], $st['value_label']); ?></div>
                            </div>
                            <div class="statement-controls">
                                <form method="POST" style="display:contents;">
                                    <input type="hidden" name="action" value="move_statement">
                                    <input type="hidden" name="item_id" value="<?php echo $i_id; ?>">
                                    <input type="hidden" name="statement_index" value="<?php echo $stmt_idx; ?>">
                                    <button name="dir" value="up" title="Move up">↑</button>
                                    <button name="dir" value="down" title="Move down">↓</button>
                                </form>
                                <form method="POST" style="display:contents;">
                                    <input type="hidden" name="action" value="delete_statement">
                                    <input type="hidden" name="item_id" value="<?php echo $i_id; ?>">
                                    <input type="hidden" name="statement_index" value="<?php echo $stmt_idx; ?>">
                                    <button style="background: #dc3545;" onclick="return confirm('Delete this statement?');">✕</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="box">
                <h3>Add Statement</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="add_statement">
                    <input type="hidden" name="item_id" value="<?php echo $i_id; ?>">
                    
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 6px;">Property</label>
                    <div style="position: relative; margin-bottom: 12px;">
                        <button type="button" id="prop_dropdown_btn" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; background: white; text-align: left; cursor: pointer; font-family: inherit; font-size: 14px;">Select property...</button>
                        <div id="prop_dropdown" class="autocomplete-results" style="display: none; position: absolute; width: 100%; top: 100%; left: 0;">
                            <input type="text" id="prop_search" placeholder="Search..." autocomplete="off" style="width: 100%; padding: 8px; border: none; border-bottom: 1px solid #ddd; font-family: inherit; font-size: 13px; box-sizing: border-box;">
                            <div id="prop_list" style="max-height: 200px; overflow-y: auto;"></div>
                        </div>
                    </div>
                    <input type="hidden" name="property_id" id="prop_id" required>
                    
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 6px;">Value</label>
                    <input type="hidden" name="value_id" id="val_id">
                    <div style="position: relative;">
                        <input type="text" name="value_label" id="val_search" placeholder="Search item..." autocomplete="off" required>
                        <div id="val_autocomplete" class="autocomplete-results"></div>
                    </div>
                    
                    <button type="submit" style="margin-top: 8px;">Save Statement</button>
                </form>
            </div>

            <div class="box">
                <h3>Appears On Lists</h3>
                <?php 
                $appears_on_lists = [];
                $all_lists = load_db('lists.json');
                
                foreach ($all_lists as $list_user_id => $user_lists) {
                    foreach ($user_lists as $list_idx => $list) {
                        foreach ($list['items'] as $list_item_idx => $list_item) {
                            if ($list_item['item_id'] === $i_id) {
                                $appears_on_lists[] = [
                                    'user_id' => $list_user_id,
                                    'user_handle' => get_handle($list_user_id),
                                    'list_idx' => $list_idx,
                                    'list_title' => $list['title']
                                ];
                            }
                        }
                    }
                }
                
                if (empty($appears_on_lists)): ?>
                    <p style="font-size: 13px; color: #999;">This item doesn't appear on any lists yet.</p>
                <?php else: ?>
                    <?php foreach ($appears_on_lists as $list_ref): ?>
                        <div class="data-item">
                            <div class="data-item-left">
                                <a href="?page=viewlist&user=<?php echo $list_ref['user_id']; ?>&idx=<?php echo $list_ref['list_idx']; ?>" class="data-item-link" style="display: block; margin-bottom: 4px;"><?php echo $list_ref['list_title']; ?></a>
                                <div class="data-item-desc">by <?php echo $list_ref['user_handle']; ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        </main>
    </div>

    <script>
    document.querySelectorAll('.markdown-renderer').forEach(function(el) {
        var rawMarkdown = el.getAttribute('data-markdown');
        if (rawMarkdown) {
            el.innerHTML = marked.parse(rawMarkdown);
        }
    });

    function setupAutocomplete(inputObj, hiddenIdObj, resultsBoxObj) {
        if(!inputObj) return;
        let timeout = null;
        inputObj.addEventListener('input', function() {
            clearTimeout(timeout);
            const q = this.value;
            resultsBoxObj.innerHTML = '';
            if(q.length < 2) return;
            
            timeout = setTimeout(() => {
                fetch('?api=local_items&q=' + encodeURIComponent(q))
                .then(res => res.json())
                .then(localData => {
                    fetch(`https://www.wikidata.org/w/api.php?action=wbsearchentities&search=${encodeURIComponent(q)}&language=en&format=json&origin=*`)
                    .then(res => res.json())
                    .then(wikiData => {
                        let combined = localData;
                        if(wikiData.search) {
                            wikiData.search.forEach(w => {
                                combined.push({id: w.id, label: w.label + ' (Wikidata: ' + (w.description || '') + ')'});
                            });
                        }
                        renderResults(combined);
                    });
                });
            }, 500);
        });

        function renderResults(data) {
            resultsBoxObj.innerHTML = '';
            data.forEach(item => {
                let div = document.createElement('div');
                div.textContent = item.label;
                div.onclick = function() {
                    inputObj.value = item.label.split(' (')[0];
                    hiddenIdObj.value = item.id;
                    resultsBoxObj.innerHTML = '';
                };
                resultsBoxObj.appendChild(div);
            });
        }
    }

    setupAutocomplete(
        document.getElementById('val_search'),
        document.getElementById('val_id'),
        document.getElementById('val_autocomplete')
    );
    setupAutocomplete(
        document.getElementById('list_item_search'),
        document.getElementById('list_item_id'),
        document.getElementById('list_autocomplete')
    );

    const propDropdownBtn = document.getElementById('prop_dropdown_btn');
    const propDropdown = document.getElementById('prop_dropdown');
    const propSearchInput = document.getElementById('prop_search');
    const propList = document.getElementById('prop_list');
    const propIdField = document.getElementById('prop_id');
    
    if (propDropdownBtn) {
        const propsData = <?php echo json_encode(load_db('data_props.json') ?: new stdClass()); ?>;
        
        propDropdownBtn.addEventListener('click', function(e) {
            e.preventDefault();
            propDropdown.style.display = propDropdown.style.display === 'none' ? 'block' : 'none';
            if (propDropdown.style.display === 'block') {
                propSearchInput.focus();
                renderPropList(Object.entries(propsData));
            }
        });
        
        propSearchInput.addEventListener('input', function() {
            const q = this.value.toLowerCase();
            const filtered = Object.entries(propsData).filter(([id, prop]) => 
                prop.label.toLowerCase().includes(q) || id.toLowerCase().includes(q)
            );
            renderPropList(filtered);
        });
        
        function renderPropList(items) {
            propList.innerHTML = '';
            items.forEach(([id, prop]) => {
                const div = document.createElement('div');
                div.textContent = prop.label + ' (' + id + ')';
                div.style.padding = '8px';
                div.style.cursor = 'pointer';
                div.style.borderBottom = '1px solid #f0f0f0';
                div.style.fontSize = '13px';
                div.onmouseover = () => div.style.background = '#f9f9f9';
                div.onmouseout = () => div.style.background = '';
                div.onclick = function() {
                    propIdField.value = id;
                    propDropdownBtn.textContent = prop.label + ' (' + id + ')';
                    propDropdown.style.display = 'none';
                    propSearchInput.value = '';
                };
                propList.appendChild(div);
            });
        }
        
        document.addEventListener('click', function(e) {
            if (!propDropdownBtn.contains(e.target) && !propDropdown.contains(e.target)) {
                propDropdown.style.display = 'none';
            }
        });
    }
    </script>
</body>
</html>