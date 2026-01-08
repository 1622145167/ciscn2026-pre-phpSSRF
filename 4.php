<?php
error_reporting(0);
session_start();

// 初始化用户会话
if (!isset($_SESSION['user'])) {
    $_SESSION['user'] = array(
        'username' => 'admin',
        'role' => 'System Administrator',
        'login_time' => date('Y-m-d H:i:s')
    );
}

if (!isset($_SESSION['stats'])) {
    $_SESSION['stats'] = array(
        'total_requests' => 0,
        'successful_requests' => 0,
        'failed_requests' => 0
    );
}

if (!isset($_SESSION['history'])) {
    $_SESSION['history'] = array();
}

class Handler {
    private $timeout = 30;
    
    public function fetch($url) {
        if (empty($url)) {
            return array('success' => false, 'error' => 'Invalid');
        }

        if (strlen($url) > 4500) {
            return array('success' => false, 'error' => 'Too long');
        }

        // 处理 gopher 协议的特殊情况
        if (preg_match('#^gopher://([^:]+):(\d+)/_(.*)$#i', $url, $matches)) {
            $host = $matches[1];
            $port = $matches[2];
            $payload = $matches[3];
            
            // URL 解码 payload
            $payload = urldecode($payload);
            if(preg_match("/MODULE|\.so|SLAVEOF|dbfilename/im",$payload)){
                exit(0);
            }
            
            return $this->gopherFetch($host, $port, $payload);
        }

        if (!preg_match('#^[a-z]+://#i', $url)) {
            $url = 'http://' . $url;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if ($error) {
            return array('success' => false, 'error' => $error);
        }

        if ($response === false) {
            return array('success' => false, 'error' => 'Failed');
        }

        $content_type = isset($info['content_type']) ? $info['content_type'] : 'text/plain';
        $encoded_content = base64_encode($response);
        
        return array(
            'success' => true,
            'content' => $encoded_content,
            'is_binary' => true,
            'type' => $content_type,
            'size' => strlen($response),
            'code' => $info['http_code'],
            'url' => $url,
            'time' => round($info['total_time'], 3)
        );
    }

    private function gopherFetch($host, $port, $payload) {
        $startTime = microtime(true);
        
        $socket = @fsockopen($host, $port, $errno, $errstr, 5);
        
        if (!$socket) {
            return array('success' => false, 'error' => 'Socket error: ' . $errstr);
        }
        
        stream_set_timeout($socket, 2);
        fwrite($socket, $payload);
        fflush($socket);
        
        $response = '';
        $maxAttempts = 50;
        $attempts = 0;
        
        while ($attempts < $maxAttempts) {
            $chunk = fread($socket, 4096);
            
            if ($chunk === false || $chunk === '') {
                $info = stream_get_meta_data($socket);
                if ($info['timed_out']) {
                    break;
                }
                $attempts++;
                usleep(20000);
                continue;
            }
            
            $response .= $chunk;
            $attempts = 0;
            
            if (preg_match('/^\+[^\r\n]*\r\n$/', $response)) {
                break;
            }
            
            if (preg_match('/^-[^\r\n]*\r\n$/', $response)) {
                break;
            }
            
            if (preg_match('/^:[^\r\n]*\r\n$/', $response)) {
                break;
            }
            
            if (preg_match('/^\$(-?\d+)\r\n/s', $response, $matches)) {
                $len = intval($matches[1]);
                if ($len === -1) {
                    if (strlen($response) >= 5) {
                        break;
                    }
                } else {
                    $expectedLen = strlen('$' . $len . "\r\n") + $len + 2;
                    if (strlen($response) >= $expectedLen) {
                        break;
                    }
                }
            }
        }
        
        fclose($socket);
        
        $elapsed = microtime(true) - $startTime;
        
        if (empty($response)) {
            return array('success' => false, 'error' => 'No response');
        }
        
        $encoded_content = base64_encode($response);
        
        return array(
            'success' => true,
            'content' => $encoded_content,
            'is_binary' => true,
            'type' => 'application/octet-stream',
            'size' => strlen($response),
            'code' => 200,
            'url' => 'gopher://' . $host . ':' . $port,
            'time' => round($elapsed, 3),
            'raw' => $response
        );
    }

    public function redisSet($url, $key, $data) {
        if (empty($url) || empty($key)) {
            return array('success' => false, 'error' => 'Invalid params');
        }

        if (preg_match('#^gopher://([^:]+):(\d+)#i', $url, $matches)) {
            $host = $matches[1];
            $port = $matches[2];
            
            $resp = "*3\r\n";
            $resp .= "\$3\r\nSET\r\n";
            $resp .= "\$" . strlen($key) . "\r\n" . $key . "\r\n";
            $resp .= "\$" . strlen($data) . "\r\n" . $data . "\r\n";
            
            $result = $this->gopherFetch($host, $port, $resp);
            
            if ($result['success']) {
                $raw_response = @base64_decode($result['content']);
                if (strpos($raw_response, '+OK') !== false) {
                    return array(
                        'success' => true,
                        'message' => 'SET command executed successfully',
                        'key' => $key,
                        'data_size' => strlen($data),
                        'response' => $result['content'],
                        'time' => $result['time']
                    );
                }
            }
            
            return $result;
        }
        
        return array('success' => false, 'error' => 'Invalid URL format');
    }

    public function preview($url) {
        $result = $this->fetch($url);
        
        if (!$result['success']) {
            return $result;
        }

        $content = base64_decode($result['content']);
        $type = $result['type'];

        if (strpos($type, 'image') !== false) {
            return array(
                'success' => true,
                'type' => 'image',
                'data' => $result['content'],
                'mime' => $type
            );
        } elseif (strpos($type, 'html') !== false || strpos($type, 'text') !== false) {
            $preview = substr($content, 0, 500);
            return array(
                'success' => true,
                'type' => 'text',
                'preview' => htmlspecialchars($preview),
                'length' => strlen($content)
            );
        } else {
            return array(
                'success' => true,
                'type' => 'other',
                'size' => strlen($content),
                'mime' => $type,
                'data' => $result['content']
            );
        }
    }
}

function handle($action, $params) {
    $h = new Handler();
    
    $_SESSION['stats']['total_requests']++;
    
    switch ($action) {
        case 'preview':
            $url = isset($params['url']) ? $params['url'] : '';
            $result = $h->preview($url);
            break;
            
        case 'fetch':
            $url = isset($params['url']) ? $params['url'] : '';
            
            if (isset($params['key']) && isset($params['data'])) {
                $key = $params['key'];
                $data = base64_decode($params['data']);
                $result = $h->redisSet($url, $key, $data);
            } else {
                $result = $h->fetch($url);
            }
            break;
        
        case 'stats':
            $result = array('success' => true, 'stats' => $_SESSION['stats']);
            break;
            
        case 'history':
            $result = array('success' => true, 'history' => array_reverse($_SESSION['history']));
            break;
            
        case 'clear_history':
            $_SESSION['history'] = array();
            $result = array('success' => true, 'message' => 'History cleared');
            break;
        
        default:
            $result = array('success' => false, 'error' => 'Unknown');
    }
    
    if ($result['success']) {
        $_SESSION['stats']['successful_requests']++;
    } else {
        $_SESSION['stats']['failed_requests']++;
    }
    
    if (isset($params['url']) && $action !== 'stats' && $action !== 'history') {
        array_push($_SESSION['history'], array(
            'url' => $params['url'],
            'time' => date('Y-m-d H:i:s'),
            'status' => $result['success'] ? 'Success' : 'Failed'
        ));
        if (count($_SESSION['history']) > 50) {
            array_shift($_SESSION['history']);
        }
    }
    
    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    exit(json_encode(handle($action, $_POST)));
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    exit(json_encode(handle($action, $_GET)));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resource Fetch Management System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f7fa;
            color: #333;
        }

        .navbar {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .navbar-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 30px;
        }

        .navbar-brand {
            font-size: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo {
            width: 32px;
            height: 32px;
            background: white;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #2c3e50;
        }

        .navbar-user {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 14px;
        }

        .user-badge {
            background: rgba(255,255,255,0.2);
            padding: 6px 12px;
            border-radius: 4px;
        }

        .container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 30px;
        }

        .main-grid {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 30px;
            margin-top: 30px;
        }

        .sidebar {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            height: fit-content;
            position: sticky;
            top: 90px;
        }

        .sidebar-title {
            font-size: 12px;
            text-transform: uppercase;
            color: #999;
            font-weight: 600;
            margin-bottom: 15px;
            letter-spacing: 0.5px;
        }

        .nav-item {
            padding: 12px 15px;
            margin-bottom: 5px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #555;
        }

        .nav-item:hover {
            background: #f5f7fa;
            color: #2c3e50;
        }

        .nav-item.active {
            background: #2c3e50;
            color: white;
        }

        .nav-icon {
            width: 18px;
            height: 18px;
            display: inline-block;
        }

        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .card-title {
            font-size: 13px;
            color: #999;
            text-transform: uppercase;
            margin-bottom: 10px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .card-value {
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
        }

        .card-subtitle {
            font-size: 12px;
            color: #999;
            margin-top: 5px;
        }

        .content-section {
            display: none;
        }

        .content-section.active {
            display: block;
        }

        .panel {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f5f7fa;
        }

        .panel-title {
            font-size: 20px;
            font-weight: 600;
            color: #2c3e50;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
            font-size: 14px;
        }

        .form-input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            font-family: 'Monaco', 'Courier New', monospace;
            transition: border-color 0.3s;
        }

        .form-input:focus {
            outline: none;
            border-color: #2c3e50;
        }

        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
        }

        .btn-primary {
            background: #2c3e50;
            color: white;
        }

        .btn-primary:hover {
            background: #1a252f;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-secondary:hover {
            background: #7f8c8d;
        }

        .btn-danger {
            background: #e74c3c;
            color: white;
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        .result-panel {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 20px;
            margin-top: 20px;
            display: none;
            max-height: 500px;
            overflow-y: auto;
        }

        .result-panel.show {
            display: block;
        }

        .result-panel.success {
            background: #d4edda;
            border-color: #c3e6cb;
        }

        .result-panel.error {
            background: #f8d7da;
            border-color: #f5c6cb;
        }

        .result-header {
            font-weight: 600;
            margin-bottom: 10px;
            color: #2c3e50;
        }

        .result-content {
            font-family: 'Monaco', 'Courier New', monospace;
            font-size: 13px;
            white-space: pre-wrap;
            word-break: break-all;
            line-height: 1.6;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            color: #555;
            border-bottom: 2px solid #e0e0e0;
        }

        .table td {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
        }

        .table tr:hover {
            background: #f8f9fa;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
        }

        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 15px;
        }

        .info-item {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
        }

        .info-label {
            font-size: 12px;
            color: #999;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 14px;
            font-weight: 500;
            color: #2c3e50;
            font-family: 'Monaco', 'Courier New', monospace;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        .protocol-badges {
            display: flex;
            gap: 8px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .protocol-badge {
            padding: 8px 12px;
            background: #e8f4f8;
            color: #2c3e50;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }

        .tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }

        .tab-btn {
            padding: 12px 20px;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            cursor: pointer;
            font-weight: 500;
            color: #777;
            transition: all 0.3s;
            margin-bottom: -2px;
        }

        .tab-btn:hover {
            color: #2c3e50;
        }

        .tab-btn.active {
            color: #2c3e50;
            border-bottom-color: #2c3e50;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="navbar-content">
            <div class="navbar-brand">
                <div class="logo">RF</div>
                <span>Resource Fetch Management</span>
            </div>
            <div class="navbar-user">
                <span><?php echo $_SESSION['user']['username']; ?></span>
                <div class="user-badge"><?php echo $_SESSION['user']['role']; ?></div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="dashboard-cards">
            <div class="card">
                <div class="card-title">Total Requests</div>
                <div class="card-value" id="totalRequests"><?php echo $_SESSION['stats']['total_requests']; ?></div>
                <div class="card-subtitle">All time</div>
            </div>
            <div class="card">
                <div class="card-title">Successful</div>
                <div class="card-value" id="successRequests" style="color: #27ae60;"><?php echo $_SESSION['stats']['successful_requests']; ?></div>
                <div class="card-subtitle">Completed successfully</div>
            </div>
            <div class="card">
                <div class="card-title">Failed</div>
                <div class="card-value" id="failedRequests" style="color: #e74c3c;"><?php echo $_SESSION['stats']['failed_requests']; ?></div>
                <div class="card-subtitle">Request errors</div>
            </div>
            <div class="card">
                <div class="card-title">Success Rate</div>
                <div class="card-value" id="successRate">
                    <?php 
                        $total = $_SESSION['stats']['total_requests'];
                        $rate = $total > 0 ? round(($_SESSION['stats']['successful_requests'] / $total) * 100) : 0;
                        echo $rate . '%';
                    ?>
                </div>
                <div class="card-subtitle">Performance metric</div>
            </div>
        </div>

        <div class="main-grid">
            <div class="sidebar">
                <div class="sidebar-title">Navigation</div>
                <div class="nav-item active" data-section="fetch">
                    <svg class="nav-icon" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z"/>
                    </svg>
                    Fetch Resource
                </div>
                <div class="nav-item" data-section="advanced">
                    <svg class="nav-icon" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM11 5a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V5zM11 13a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                    </svg>
                    Advanced Tools
                </div>
                <div class="nav-item" data-section="history">
                    <svg class="nav-icon" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z"/>
                    </svg>
                    Request History
                </div>
                <div class="nav-item" data-section="settings">
                    <svg class="nav-icon" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z"/>
                    </svg>
                    System Info
                </div>
            </div>

            <div class="main-content">
                <!-- Fetch Resource Section -->
                <div class="content-section active" id="fetch-section">
                    <div class="panel">
                        <div class="panel-header">
                            <div class="panel-title">Resource Fetcher</div>
                        </div>

                        <div class="tabs">
                            <button class="tab-btn active" data-tab="quick">Quick Fetch</button>
                            <button class="tab-btn" data-tab="preview">Preview Mode</button>
                        </div>

                        <div id="quick-tab" class="tab-content" style="display: block;">
                            <div class="form-group">
                                <label class="form-label">Resource URL</label>
                                <input type="text" class="form-input" id="fetchUrl" placeholder="http://example.com/resource">
                            </div>

                            <div class="button-group">
                                <button class="btn btn-primary" onclick="doFetch()">Execute Fetch</button>
                                <button class="btn btn-secondary" onclick="clearResult('fetch')">Clear</button>
                            </div>

                            <div id="fetchResult" class="result-panel"></div>
                        </div>

                        <div id="preview-tab" class="tab-content" style="display: none;">
                            <div class="form-group">
                                <label class="form-label">Resource URL</label>
                                <input type="text" class="form-input" id="previewUrl" placeholder="http://example.com/image.png">
                            </div>

                            <div class="button-group">
                                <button class="btn btn-primary" onclick="doPreview()">Preview Resource</button>
                                <button class="btn btn-secondary" onclick="clearResult('preview')">Clear</button>
                            </div>

                            <div id="previewResult" class="result-panel"></div>
                        </div>
                    </div>
                </div>

                <!-- Advanced Tools Section -->
                <div class="content-section" id="advanced-section">
                    <div class="panel">
                        <div class="panel-header">
                            <div class="panel-title">Advanced Protocol Tools</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Target URL</label>
                            <input type="text" class="form-input" id="advancedUrl" placeholder="url">
                        </div>

                        <div class="info-grid">
                            <div class="form-group">
                                <label class="form-label">data</label>
                                <input type="text" class="form-input" id="advancedKey" placeholder="data">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Value</label>
                                <input type="text" class="form-input" id="advancedData" placeholder="dGVzdF92YWx1ZQ==">
                            </div>
                        </div>

                        <div class="button-group">
                            <button class="btn btn-primary" onclick="doAdvanced()">Execute</button>
                            <button class="btn btn-secondary" onclick="clearResult('advanced')">Clear</button>
                        </div>

                        <div id="advancedResult" class="result-panel"></div>
                    </div>
                </div>

                <!-- History Section -->
                <div class="content-section" id="history-section">
                    <div class="panel">
                        <div class="panel-header">
                            <div class="panel-title">Request History</div>
                            <button class="btn btn-danger" onclick="clearHistory()">Clear History</button>
                        </div>

                        <div id="historyContent">
                            <?php if (count($_SESSION['history']) > 0): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>URL</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="historyTable">
                                    <?php foreach(array_reverse($_SESSION['history']) as $item): ?>
                                    <tr>
                                        <td><?php echo $item['time']; ?></td>
                                        <td style="font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars(substr($item['url'], 0, 80)); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $item['status'] === 'Success' ? 'success' : 'danger'; ?>">
                                                <?php echo $item['status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">📋</div>
                                <div>No requests recorded yet</div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Settings Section -->
                <div class="content-section" id="settings-section">
                    <div class="panel">
                        <div class="panel-header">
                            <div class="panel-title">System Information</div>
                        </div>

                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">System Version</div>
                                <div class="info-value">2.0.1</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">PHP Version</div>
                                <div class="info-value"><?php echo phpversion(); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">cURL Support</div>
                                <div class="info-value"><?php echo function_exists('curl_init') ? 'Enabled' : 'Disabled'; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Session ID</div>
                                <div class="info-value" style="font-size: 11px;"><?php echo session_id(); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Login Time</div>
                                <div class="info-value" style="font-size: 12px;"><?php echo $_SESSION['user']['login_time']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Server Time</div>
                                <div class="info-value" style="font-size: 12px;"><?php echo date('Y-m-d H:i:s'); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="panel" style="margin-top: 20px;">
                        <div class="panel-header">
                            <div class="panel-title">Supported Protocols</div>
                        </div>
                        <div class="protocol-badges">
                            <div class="protocol-badge">HTTP</div>
                            <div class="protocol-badge">HTTPS</div>
                            <div class="protocol-badge">FTP</div>
                            <div class="protocol-badge">GOPHER</div>
                        </div>
                        <p style="margin-top: 20px; color: #777; font-size: 14px; line-height: 1.6;">
                            The system supports multiple protocols for resource fetching. Each protocol has different capabilities and use cases. 
                            Gopher protocol support is provided for legacy system compatibility.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Navigation
        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', function() {
                document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
                this.classList.add('active');
                
                const section = this.dataset.section;
                document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
                document.getElementById(section + '-section').classList.add('active');
            });
        });

        // Tabs
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const parent = this.closest('.panel');
                parent.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                const tab = this.dataset.tab;
                parent.querySelectorAll('.tab-content').forEach(t => t.style.display = 'none');
                parent.querySelector('#' + tab + '-tab').style.display = 'block';
            });
        });

        function updateStats() {
            fetch('?action=stats')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('totalRequests').textContent = data.stats.total_requests;
                        document.getElementById('successRequests').textContent = data.stats.successful_requests;
                        document.getElementById('failedRequests').textContent = data.stats.failed_requests;
                        const total = data.stats.total_requests;
                        const rate = total > 0 ? Math.round((data.stats.successful_requests / total) * 100) : 0;
                        document.getElementById('successRate').textContent = rate + '%';
                    }
                });
        }

        function doFetch() {
            const url = document.getElementById('fetchUrl').value;
            if (!url.trim()) {
                alert('Please enter a URL');
                return;
            }

            const resultDiv = document.getElementById('fetchResult');
            resultDiv.innerHTML = '<div class="result-header">Processing request...</div>';
            resultDiv.className = 'result-panel show';

            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=fetch&url=' + encodeURIComponent(url)
            })
            .then(res => res.json())
            .then(data => {
                resultDiv.className = 'result-panel show ' + (data.success ? 'success' : 'error');
                
                if (!data.success) {
                    resultDiv.innerHTML = '<div class="result-header">Request Failed</div><div class="result-content">Error: ' + data.error + '</div>';
                } else {
                    let html = '<div class="result-header">Request Successful</div>';
                    html += '<div class="info-grid" style="margin-bottom: 15px;">';
                    html += '<div class="info-item"><div class="info-label">Size</div><div class="info-value">' + data.size + ' bytes</div></div>';
                    html += '<div class="info-item"><div class="info-label">Type</div><div class="info-value">' + data.type + '</div></div>';
                    html += '<div class="info-item"><div class="info-label">HTTP Code</div><div class="info-value">' + data.code + '</div></div>';
                    html += '<div class="info-item"><div class="info-label">Time</div><div class="info-value">' + data.time + 's</div></div>';
                    html += '</div>';
                    html += '<div class="result-content">Base64 Data (first 500 chars):\n' + data.content.substring(0, 500) + '...</div>';
                    resultDiv.innerHTML = html;
                }
                updateStats();
                loadHistory();
            });
        }

        function doPreview() {
            const url = document.getElementById('previewUrl').value;
            if (!url.trim()) {
                alert('Please enter a URL');
                return;
            }

            const resultDiv = document.getElementById('previewResult');
            resultDiv.innerHTML = '<div class="result-header">Loading preview...</div>';
            resultDiv.className = 'result-panel show';

            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=preview&url=' + encodeURIComponent(url)
            })
            .then(res => res.json())
            .then(data => {
                resultDiv.className = 'result-panel show ' + (data.success ? 'success' : 'error');
                
                if (!data.success) {
                    resultDiv.innerHTML = '<div class="result-header">Preview Failed</div><div class="result-content">Error: ' + data.error + '</div>';
                } else if (data.type === 'image') {
                    resultDiv.innerHTML = '<div class="result-header">Image Preview</div><div style="text-align: center; padding: 20px;"><img src="data:' + data.mime + ';base64,' + data.data + '" style="max-width: 100%; border-radius: 6px;"></div>';
                } else if (data.type === 'text') {
                    resultDiv.innerHTML = '<div class="result-header">Text Preview</div><div class="result-content">' + data.preview + '\n\nTotal Size: ' + data.length + ' bytes</div>';
                } else {
                    resultDiv.innerHTML = '<div class="result-header">Binary Data</div><div class="result-content">Size: ' + data.size + ' bytes\nType: ' + data.mime + '\n\nBase64 Preview:\n' + data.data.substring(0, 500) + '...</div>';
                }
                updateStats();
                loadHistory();
            });
        }

        function doAdvanced() {
            const url = document.getElementById('advancedUrl').value;
            const key = document.getElementById('advancedKey').value;
            const data = document.getElementById('advancedData').value;

            if (!url.trim()) {
                alert('Please enter a URL');
                return;
            }

            const resultDiv = document.getElementById('advancedResult');
            resultDiv.innerHTML = '<div class="result-header">Executing...</div>';
            resultDiv.className = 'result-panel show';

            let body = 'action=fetch&url=' + encodeURIComponent(url);
            if (key && data) {
                body += '&key=' + encodeURIComponent(key) + '&data=' + encodeURIComponent(data);
            }

            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body
            })
            .then(res => res.json())
            .then(data => {
                resultDiv.className = 'result-panel show ' + (data.success ? 'success' : 'error');
                resultDiv.innerHTML = '<div class="result-header">Response</div><div class="result-content">' + JSON.stringify(data, null, 2) + '</div>';
                updateStats();
                loadHistory();
            });
        }

        function clearResult(type) {
            const resultDiv = document.getElementById(type + 'Result');
            resultDiv.style.display = 'none';
            resultDiv.className = 'result-panel';
            document.getElementById(type + 'Url').value = '';
        }

        function loadHistory() {
            fetch('?action=history')
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.history.length > 0) {
                        let html = '<table class="table"><thead><tr><th>Time</th><th>URL</th><th>Status</th></tr></thead><tbody>';
                        data.history.forEach(item => {
                            html += '<tr>';
                            html += '<td>' + item.time + '</td>';
                            html += '<td style="font-family: monospace; font-size: 12px;">' + item.url.substring(0, 80) + '</td>';
                            html += '<td><span class="badge badge-' + (item.status === 'Success' ? 'success' : 'danger') + '">' + item.status + '</span></td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table>';
                        document.getElementById('historyContent').innerHTML = html;
                    }
                });
        }

        function clearHistory() {
            if (confirm('Are you sure you want to clear the history?')) {
                fetch('?action=clear_history')
                    .then(res => res.json())
                    .then(data => {
                        document.getElementById('historyContent').innerHTML = '<div class="empty-state"><div class="empty-state-icon">📋</div><div>No requests recorded yet</div></div>';
                    });
            }
        }

        setInterval(updateStats, 5000);
    </script>
</body>
</html>
