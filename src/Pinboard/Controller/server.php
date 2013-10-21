<?php

use Pinboard\Utils\Utils;
use Symfony\Component\HttpFoundation\Request;

$ROW_PER_PAGE = 50;
$rowPerPage = isset($app['params']['pagination']['row_per_page']) ? $app['params']['pagination']['row_per_page'] : $ROW_PER_PAGE;
$rowPerPage = ($rowPerPage > 0) ? $rowPerPage : $ROW_PER_PAGE;

$server = $app['controllers_factory'];

function checkUserAccess($app, $serverName) {
    $hostsRegExp = ".*";
    if (isset($app['params']['secure']['enable'])) {
        if ($app['params']['secure']['enable'] == "true") {
            $user = $app['security']->getToken()->getUser();
            $hostsRegExp = isset($app['params']['secure']['users'][$user->getUsername()]['hosts'])
                        ? $app['params']['secure']['users'][$user->getUsername()]['hosts']
                        : ".*";
            if (trim($hostsRegExp) == "") {
                $hosts = ".*";
            }
        }
    }

    if (!preg_match("/" . $hostsRegExp . "/", $serverName)) {
        $app->abort(403, "Access denied");
    }
}

$server->get('/{serverName}/{hostName}/overview.{format}', function($serverName, $hostName, $format) use ($app) {
    checkUserAccess($app, $serverName);

    $result = array();
    $result['hosts']       = getHosts($app['db'], $serverName);
    $result['req']         = getRequestReview($app['db'], $serverName, $hostName);
    $result['req_per_sec'] = getRequestPerSecReview($app['db'], $serverName, $hostName);
    $result['statuses']    = getStatusesReview($app['db'], $serverName, $hostName);

    if ($format == 'html') {
        $result['server_name'] = $serverName;
        $result['hostname'] = $hostName;
        $result['title'] = $serverName;

        return $app['twig']->render(
            'server.html.twig',
            $result
        );
    }
    if ($format == 'json') {
        unset($result['hosts']);
        foreach ($result['req'] as &$value) {
            unset($value['date']);
        }
        foreach ($result['req_per_sec'] as &$value) {
            unset($value['date']);
        }
        foreach ($result['statuses']['data'] as &$value) {
            unset($value['date']);
        }

        $result['success'] = 'true';
        $response = new Symfony\Component\HttpFoundation\JsonResponse($result);
        $response->setStatusCode(200);
        return $response;
    }
})
->value('hostName', 'all')
->value('format', 'html')
->assert('format', 'json|html')
->bind('server');

function getHosts($conn, $serverName) {
    $sql = '
        SELECT
            DISTINCT hostname
        FROM
            ipm_report_by_hostname_and_server
        WHERE
            server_name = :server_name
        ORDER BY
            hostname
    ';

    $stmt = $conn->executeQuery($sql, array('server_name' => $serverName));
    $hosts = array();
    while ($data = $stmt->fetch()) {
      $hosts[] = $data['hostname'];
    }

    return $hosts;
}

function getStatusesReview($conn, $serverName, $hostName) {
    $params = array(
        'server_name' => $serverName,
        'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
    );
    $hostCondition = '';

    if ($hostName != 'all') {
        $params['hostname'] = $hostName;
        $hostCondition = 'AND hostname = :hostname';
    }

    $sql = '
        SELECT
            created_at, status, count(*) as cnt
        FROM
            ipm_status_details
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            AND created_at > :created_at
        GROUP BY
            created_at
        ORDER BY
            created_at
    ';

    $stmt = $conn->executeQuery($sql, $params);

    $statuses = array(
        'data'  => array(),
        'codes' => array(),
    );
    while ($data = $stmt->fetch()) {
        $t = strtotime($data['created_at']);
        $date = date('Y,', $t) . (date('n', $t) - 1) . date(',d,H,i', $t);

        $statuses['data'][] = array(
            'created_at' => $data['created_at'],
            'date' => $date,
            'error_code' => $data['status'],
            'error_count' => $data['cnt'],
        );
        if (!isset($statuses['codes'][$data['status']])) {
            //set color
            $statuses['codes'][$data['status']] = Utils::generateColor();
        }
    }
    ksort($statuses['codes']);

    return $statuses;
}

function getRequestPerSecReview($conn, $serverName, $hostName) {
    $params = array(
        'server_name' => $serverName,
        'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
    );
    $table = 'ipm_report_by_server_name';
    $hostCondition = '';

    if ($hostName != 'all') {
        $params['hostname'] = $hostName;
        $table = 'ipm_report_by_hostname_and_server';
        $hostCondition = 'AND hostname = :hostname';
    }

    $sql = '
        SELECT
            created_at, req_per_sec
        FROM
            ' . $table . '
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            AND created_at > :created_at
        ORDER BY
            created_at
    ';

    $data = $conn->fetchAll($sql, $params);

    foreach($data as &$item) {
        $t = strtotime($item['created_at']);
        $item['date'] = date('Y,', $t) . (date('n', $t) - 1) . date(',d,H,i', $t);
        $item['req_per_sec'] = number_format($item['req_per_sec'], 2, '.', '');
    }

    return $data;
}

function getRequestReview($conn, $serverName, $hostName) {
    $params = array(
        'server_name' => $serverName,
        'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
    );
    $hostCondition = '';
    $index = 'sn_c';

    if ($hostName != 'all') {
        $params['hostname'] = $hostName;
        $hostCondition = 'AND hostname = :hostname';
        $index = 'sn_h_c';
    }

    $sql = '
        SELECT
            created_at,
            req_time_90, req_time_95, req_time_99, req_time_100,
            mem_peak_usage_90, mem_peak_usage_95, mem_peak_usage_99, mem_peak_usage_100,
            cpu_peak_usage_90, cpu_peak_usage_95, cpu_peak_usage_99, cpu_peak_usage_100
        FROM
            ipm_report_2_by_hostname_and_server
        USE INDEX
            (' . $index . ')
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            AND created_at > :created_at
        ORDER BY
            created_at
    ';

    $data = $conn->fetchAll($sql, $params);

    foreach($data as &$item) {
        $t = strtotime($item['created_at']);
        $item['date'] = date('Y,', $t) . (date('n', $t) - 1) . date(',d,H,i', $t);
        $item['req_time_90']  = number_format($item['req_time_90'] * 1000, 0, '.', '');
        $item['req_time_95']  = number_format($item['req_time_95'] * 1000, 0, '.', '');
        $item['req_time_99']  = number_format($item['req_time_99'] * 1000, 0, '.', '');
        $item['req_time_100'] = number_format($item['req_time_100'] * 1000, 0, '.', '');
        $item['mem_peak_usage_90']  = number_format($item['mem_peak_usage_90'], 0, '.', '');
        $item['mem_peak_usage_95']  = number_format($item['mem_peak_usage_95'], 0, '.', '');
        $item['mem_peak_usage_99']  = number_format($item['mem_peak_usage_99'], 0, '.', '');
        $item['mem_peak_usage_100'] = number_format($item['mem_peak_usage_100'], 0, '.', '');
        $item['cpu_peak_usage_90']  = number_format($item['cpu_peak_usage_90'], 0, '.', '');
        $item['cpu_peak_usage_95']  = number_format($item['cpu_peak_usage_95'], 0, '.', '');
        $item['cpu_peak_usage_99']  = number_format($item['cpu_peak_usage_99'], 0, '.', '');
        $item['cpu_peak_usage_100'] = number_format($item['cpu_peak_usage_100'], 0, '.', '');
    }

    return $data;
}


$server->get('/{serverName}/{hostName}/statuses/{pageNum}/{colOrder}/{colDir}', function($serverName, $hostName, $pageNum, $colOrder, $colDir) use ($app, $rowPerPage) {
    checkUserAccess($app, $serverName);

    $pageNum = str_replace('page', '', $pageNum);

    $result = array(
        'server_name' => $serverName,
        'hostname'    => $hostName,
        'title'       => 'Error pages / ' . $serverName,
        'pageNum'     => $pageNum,
    );

    $result['rowPerPage'] = $rowPerPage;

    $rowCount = getErrorPagesCount($app['db'], $serverName, $hostName);
    $result['rowCount'] = $rowCount;

    $pageCount = ceil($rowCount / $rowPerPage);
    $result['pageCount'] = $pageCount;

    if ($pageCount != 0) {
        if ($pageNum < 1 || $pageNum > $pageCount) {
            $app->abort(404, "Page $pageNum does not exist.");
        }
    }

    $startPos = ($pageNum - 1) * $rowPerPage;
    $result['hosts']    = getHosts($app['db'], $serverName);
    $result['statuses'] = getErrorPages($app['db'], $serverName, $hostName, $startPos, $rowPerPage, $colOrder, $colDir);

    return $app['twig']->render(
        'statuses.html.twig',
        $result
    );
})
->value('hostName', 'all')
->value('pageNum', 'page1')
->value('colOrder', null)
->value('colDir', null)
->assert('pageNum', 'page\d+')
->bind('server_statuses');

function getErrorPagesCount($conn, $serverName, $hostName) {
    $params = array(
        'server_name' => $serverName,
        'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
    );
    $hostCondition = '';

    if ($hostName != 'all') {
        $params['hostname'] = $hostName;
        $hostCondition = 'AND hostname = :hostname';
    }

    $sql = '
        SELECT
            COUNT(*)
        FROM
            ipm_status_details
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            AND created_at > :created_at
    ';

    $data = $conn->fetchAll($sql, $params);

    return (int)$data[0]['COUNT(*)'];
}

function getErrorPages($conn, $serverName, $hostName, $startPos, $rowCount, $colOrder, $colDir) {
    $params = array(
        'server_name' => $serverName,
        'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
    );
    $hostCondition = '';

    if ($hostName != 'all') {
        $params['hostname'] = $hostName;
        $hostCondition = 'AND hostname = :hostname';
    }

    $orderBy = 'created_at DESC';
    if (null !== $colOrder) {
        $orderBy = generateOrderBy($colOrder, $colDir, 'ipm_status_details');
    }

    $sql = '
        SELECT
            DISTINCT server_name, hostname, script_name, status, created_at
        FROM
            ipm_status_details
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            AND created_at > :created_at
        ORDER BY
            ' . $orderBy. '
        LIMIT
            ' . $startPos . ', ' . $rowCount . '
    ';

    $data = $conn->fetchAll($sql, $params);

    return $data;
}

$server->get('/{serverName}/{hostName}/req-time/{pageNum}/{colOrder}/{colDir}', function($serverName, $hostName, $pageNum, $colOrder, $colDir) use ($app, $rowPerPage) {
    checkUserAccess($app, $serverName);

    $pageNum = str_replace('page', '', $pageNum);

    $result = array(
        'server_name' => $serverName,
        'hostname'    => $hostName,
        'title'       => 'Request time / ' . $serverName,
        'pageNum'     => $pageNum,
        'colOrder'    => $colOrder,
        'colDir'      => $colDir
    );

    $result['rowPerPage'] = $rowPerPage;

    $rowCount = getSlowPagesCount($app['db'], $serverName, $hostName);
    $result['rowCount'] = $rowCount;

    $pageCount = ceil($rowCount / $rowPerPage);
    $result['pageCount'] = $pageCount;
    if ($pageCount != 0) {
        if ($pageNum < 1 || $pageNum > $pageCount) {
            $app->abort(404, "Page $pageNum does not exist.");
        }
    }
    $startPos = ($pageNum - 1) * $rowPerPage;

    $result['hosts'] = getHosts($app['db'], $serverName);
    $result['pages'] = getSlowPages($app['db'], $serverName, $hostName, $startPos, $rowPerPage, $colOrder, $colDir);

    return $app['twig']->render(
        'req_time.html.twig',
        $result
    );
})
->value('hostName', 'all')
->value('pageNum', 'page1')
->value('colOrder', null)
->value('colDir', null)
->assert('pageNum', 'page\d+')
->bind('server_req_time');

function getSlowPagesCount($conn, $serverName, $hostName) {
    $params = array(
        'server_name' => $serverName,
        'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
    );
    $hostCondition = '';

    if ($hostName != 'all') {
        $params['hostname'] = $hostName;
        $hostCondition = 'AND hostname = :hostname';
    }

    $sql = '
        SELECT
            COUNT(*)
        FROM
            ipm_req_time_details
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            AND created_at > :created_at
    ';

    $data = $conn->fetchAll($sql, $params);

    return (int)$data[0]['COUNT(*)'];
}

function getSlowPages($conn, $serverName, $hostName, $startPos, $rowCount, $colOrder, $colDir) {
    $params = array(
        'server_name' => $serverName,
        'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
    );
    $hostCondition = '';

    if ($hostName != 'all') {
        $params['hostname'] = $hostName;
        $hostCondition = 'AND hostname = :hostname';
    }

    $orderBy = 'created_at DESC, req_time DESC';
    if (null !== $colOrder) {
        $orderBy = generateOrderBy($colOrder, $colDir, 'ipm_req_time_details');
    }

    $sql = '
        SELECT
            DISTINCT server_name, hostname, script_name, req_time, created_at
        FROM
            ipm_req_time_details
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            AND created_at > :created_at
        ORDER BY
            ' . $orderBy .'
        LIMIT
            ' . $startPos . ', ' . $rowCount . '
    ';

    $data = $conn->fetchAll($sql, $params);

    foreach($data as &$item) {
        $item['req_time']  = number_format($item['req_time'] * 1000, 0, '.', ',');
    }

    return $data;
}


$server->get('/{serverName}/{hostName}/script-sum/{pageNum}/{colOrder}/{colDir}', function($serverName, $hostName, $pageNum, $colOrder, $colDir) use ($app, $rowPerPage) {

   checkUserAccess($app, $serverName);

   $pageNum = str_replace('page', '', $pageNum);

   $script_name = $_GET['script_name'];

   $result = array(
      'server_name' => $serverName,
      'hostname'    => $hostName,
      'title'       => 'Script summary ' . $script_name,
      'pageNum'     => $pageNum,
      'colOrder'    => $colOrder,
      'colDir'      => $colDir
   );

   $result['rowPerPage'] = $rowPerPage;

   $rowCount = getScriptSumCount($app['db'], $serverName, $hostName, $script_name);

   $result['rowCount'] = $rowCount;

   $pageCount = ceil($rowCount / $rowPerPage);
   $result['pageCount'] = $pageCount;
   if ($pageCount != 0) {
      if ($pageNum < 1 || $pageNum > $pageCount) {
         $app->abort(404, "Page $pageNum does not exist.");
      }
   }
   $startPos = ($pageNum - 1) * $rowPerPage;

   $result['hosts'] = getHosts($app['db'], $serverName);
   $result['pages'] = getScriptSum($app['db'], $serverName, $hostName, $startPos, $rowPerPage, $colOrder, $colDir, $script_name);
   $result['script_name'] = $script_name;

   return $app['twig']->render(
      'script_sum.html.twig',
      $result
   );

})
->value('hostName', 'all')
->value('pageNum', 'page1')
->value('colOrder', null)
->value('colDir', null)
->assert('pageNum', 'page\d+')
->bind('server_script_sum');

$server->get('/{serverName}/{hostName}/mem-usage/{pageNum}/{colOrder}/{colDir}', function($serverName, $hostName, $pageNum, $colOrder, $colDir) use ($app, $rowPerPage) {
    checkUserAccess($app, $serverName);

    $pageNum = str_replace('page', '', $pageNum);

    $result = array(
        'server_name' => $serverName,
        'hostname'    => $hostName,
        'title'       => 'Memory peak usage / ' . $serverName,
        'pageNum'     => $pageNum,
        'colOrder'    => $colOrder,
        'colDir'      => $colDir
    );

    $result['rowPerPage'] = $rowPerPage;

    $rowCount = getHeavyPagesCount($app['db'], $serverName, $hostName);
    $result['rowCount'] = $rowCount;

    $pageCount = ceil($rowCount / $rowPerPage);
    $result['pageCount'] = $pageCount;
    if ($pageCount != 0) {
        if ($pageNum < 1 || $pageNum > $pageCount) {
            $app->abort(404, "Page $pageNum does not exist.");
        }
    }
    $startPos = ($pageNum - 1) * $rowPerPage;

    $result['hosts'] = getHosts($app['db'], $serverName);
    $result['pages'] = getHeavyPages($app['db'], $serverName, $hostName, $startPos, $rowPerPage, $colOrder, $colDir);

    return $app['twig']->render(
        'mem_usage.html.twig',
        $result
    );
})
->value('hostName', 'all')
->value('pageNum', 'page1')
->value('colOrder', null)
->value('colDir', null)
->assert('pageNum', 'page\d+')
->bind('server_mem_usage');

function getHeavyPagesCount($conn, $serverName, $hostName){
    $params = array(
        'server_name' => $serverName,
        'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
    );
    $hostCondition = '';

    if ($hostName != 'all') {
        $params['hostname'] = $hostName;
        $hostCondition = 'AND hostname = :hostname';
    }

    $sql = '
        SELECT
            COUNT(*)
        FROM
            ipm_mem_peak_usage_details
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            AND created_at > :created_at
    ';

    $data = $conn->fetchAll($sql, $params);

    return (int)$data[0]['COUNT(*)'];
}

function getScriptSumCount($conn, $serverName, $hostName, $script_name)
{

   $params = array(
      'server_name' => $serverName,
      'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
   );
   $hostCondition = '';
   $scriptCondition = '';

   if ($hostName != 'all') {
      $params['hostname'] = $hostName;
      $hostCondition = 'AND hostname = :hostname';
   }

   if ($script_name !== null)
   {
      $params['script_name'] = $script_name;
      $scriptCondition = 'AND script_name = :script_name';
   }

   $sql = '
        SELECT
            COUNT(*)
        FROM
            ipm_script_details
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            ' . $scriptCondition . '
            AND created_at > :created_at
    ';

   $data = $conn->fetchAll($sql, $params);

   return (int)$data[0]['COUNT(*)'];
}

function getCPUPagesCount($conn, $serverName, $hostName){
   $params = array(
      'server_name' => $serverName,
      'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
   );
   $hostCondition = '';

   if ($hostName != 'all') {
      $params['hostname'] = $hostName;
      $hostCondition = 'AND hostname = :hostname';
   }

   $sql = '
        SELECT
            COUNT(*)
        FROM
            ipm_cpu_usage_details
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            AND created_at > :created_at
    ';

   $data = $conn->fetchAll($sql, $params);

   return (int)$data[0]['COUNT(*)'];
}

function getCPUPages($conn, $serverName, $hostName, $startPos, $rowCount, $colOrder, $colDir){
   $params = array(
      'server_name' => $serverName,
      'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
   );
   $hostCondition = '';

   if ($hostName != 'all') {
      $params['hostname'] = $hostName;
      $hostCondition = 'AND hostname = :hostname';
   }

   $orderBy = 'created_at DESC, cpu_peak_usage DESC';
   if (null !== $colOrder) {
      $orderBy = generateOrderBy($colOrder, $colDir, 'ipm_cpu_usage_details');
   }

   $sql = '
        SELECT
            DISTINCT server_name, hostname, script_name, cpu_peak_usage, created_at
        FROM
            ipm_cpu_usage_details
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            AND created_at > :created_at
        ORDER BY
            ' . $orderBy .'
        LIMIT
            ' . $startPos . ', ' . $rowCount . '
    ';

   $data = $conn->fetchAll($sql, $params);

   foreach($data as &$item) {
      $item['cpu_peak_usage']  = number_format($item['cpu_peak_usage'], 0, '.', ',');
   }

   return $data;
}


function getScriptSum($conn, $serverName, $hostName, $startPos, $rowCount, $colOrder, $colDir, $script_name)
{

   $params = array(
      'server_name' => $serverName,
      'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
   );
   $hostCondition = '';
   $scriptCondition = '';

   if ($hostName != 'all') {
      $params['hostname'] = $hostName;
      $hostCondition = 'AND hostname = :hostname';
   }

   if ($script_name !== null)
   {
      $params['script_name'] = $script_name;
      $scriptCondition = 'AND script_name = :script_name';
   }

   $orderBy = 'created_at DESC';
   if (null !== $colOrder) {
      $orderBy = generateOrderBy($colOrder, $colDir, 'ipm_script_details');
   }

   $sql = '
        SELECT
            DISTINCT server_name, hostname, script_name, cpu_peak_usage, mem_peak_usage, req_time, created_at
        FROM
            ipm_script_details
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            ' . $scriptCondition . '
            AND created_at > :created_at
        ORDER BY
            ' . $orderBy .'
        LIMIT
            ' . $startPos . ', ' . $rowCount . '
    ';

   $data = $conn->fetchAll($sql, $params);
   foreach($data as &$item) {
      $item['mem_peak_usage']  = number_format($item['mem_peak_usage'], 0, '.', ',');
   }

   return $data;
}

function getHeavyPages($conn, $serverName, $hostName, $startPos, $rowCount, $colOrder, $colDir) {
    $params = array(
        'server_name' => $serverName,
        'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
    );
    $hostCondition = '';

    if ($hostName != 'all') {
        $params['hostname'] = $hostName;
        $hostCondition = 'AND hostname = :hostname';
    }

    $orderBy = 'created_at DESC, mem_peak_usage DESC';
    if (null !== $colOrder) {
        $orderBy = generateOrderBy($colOrder, $colDir, 'ipm_mem_peak_usage_details');
    }

    $sql = '
        SELECT
            DISTINCT server_name, hostname, script_name, mem_peak_usage, created_at
        FROM
            ipm_mem_peak_usage_details
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            AND created_at > :created_at
        ORDER BY
            ' . $orderBy .'
        LIMIT
            ' . $startPos . ', ' . $rowCount . '
    ';

    $data = $conn->fetchAll($sql, $params);

    foreach($data as &$item) {
        $item['mem_peak_usage']  = number_format($item['mem_peak_usage'], 0, '.', ',');
    }

    return $data;
}

$server->get('/{serverName}/{hostName}/cpu-usage/{pageNum}/{colOrder}/{colDir}', function($serverName, $hostName, $pageNum, $colOrder, $colDir) use ($app, $rowPerPage) {
   checkUserAccess($app, $serverName);

   $pageNum = str_replace('page', '', $pageNum);

   $result = array(
      'server_name' => $serverName,
      'hostname'    => $hostName,
      'title'       => 'Memory peak usage / ' . $serverName,
      'pageNum'     => $pageNum,
      'colOrder'    => $colOrder,
      'colDir'      => $colDir
   );

   $result['rowPerPage'] = $rowPerPage;

   $rowCount = getCPUPagesCount($app['db'], $serverName, $hostName);
   $result['rowCount'] = $rowCount;

   $pageCount = ceil($rowCount / $rowPerPage);
   $result['pageCount'] = $pageCount;
   if ($pageCount != 0) {
      if ($pageNum < 1 || $pageNum > $pageCount) {
         $app->abort(404, "Page $pageNum does not exist.");
      }
   }
   $startPos = ($pageNum - 1) * $rowPerPage;

   $result['hosts'] = getHosts($app['db'], $serverName);
   $result['pages'] = getCPUPages($app['db'], $serverName, $hostName, $startPos, $rowPerPage, $colOrder, $colDir);

   return $app['twig']->render(
      'cpu_usage.html.twig',
      $result
   );
})
->value('hostName', 'all')
->value('pageNum', 'page1')
->value('colOrder', null)
->value('colDir', null)
->assert('pageNum', 'page\d+')
->bind('server_cpu_usage');


$server->get('/{serverName}/{hostName}/live', function(Request $request, $serverName, $hostName) use ($app) {
    checkUserAccess($app, $serverName);

    if ($request->isXmlHttpRequest()) {
        $result = array(
            'pages' => getLivePages($app['db'], $serverName, $hostName, $request->get('last_id')),
        );

        return $app->json($result);
    }

    $result = array(
        'server_name' => $serverName,
        'hostname'    => $hostName,
        'title'       => 'Live / ' . $serverName,
        'limit'       => 100,
    );

    $result['hosts'] = getHosts($app['db'], $serverName);
    $result['pages'] = getLivePages($app['db'], $serverName, $hostName, null, $result['limit']);
    $result['last_id'] = sizeof($result['pages']) ? $result['pages'][0]['id'] : 0;

    return $app['twig']->render(
        'live.html.twig',
        $result
    );
})
->value('hostName', 'all')
->bind('server_live');


function getLivePages($conn, $serverName, $hostName, $lastId = null, $limit = 50) {
    $params = array(
        'server_name' => $serverName,
    );
    $hostCondition = '';
    $idCondition   = '';

    if ($hostName != 'all') {
        $params['hostname'] = $hostName;
        $hostCondition = 'AND hostname = :hostname';
    }
    if ($lastId > 0) {
        $params['last_id'] = $lastId;
        $idCondition = 'AND id > :last_id';
    }

    $sql = '
        SELECT
            id, server_name, hostname, script_name, req_time, status, mem_peak_usage, ru_utime
        FROM
            request
        WHERE
            server_name = :server_name
            ' . $hostCondition . '
            ' . $idCondition . '
        ORDER BY
            id DESC
        LIMIT
            ' . $limit . '
    ';

    $data = $conn->fetchAll($sql, $params);

    foreach($data as &$item) {
        $item['req_time']        = $item['req_time'] * 1000;
        $item['mem_peak_usage']  = $item['mem_peak_usage'];
        $item['req_time_format']        = number_format($item['req_time'], 0, '.', ',');
        $item['mem_peak_usage_format']  = number_format($item['mem_peak_usage'], 0, '.', ',');
    }

    return $data;
}

function generateOrderBy($colOrder, $colDir, $table) {
    $orderBy = 'created_at DESC';
    if (null !== $colOrder) {
        if ('asc' == $colDir) {
            $dir = 'ASC';
        } else {
            $dir = 'DESC';
        }

        if ('ipm_req_time_details' == $table && 'time' == $colOrder) {
            $orderBy = 'req_time ' . $dir . ', created_at DESC';
        } elseif ('ipm_mem_peak_usage_details' == $table && 'mem' == $colOrder) {
            $orderBy = 'mem_peak_usage ' . $dir . ', created_at DESC';
        } else {
            switch ($colOrder) {
                case 'host':
                    $orderBy = 'hostname ' . $dir . ', created_at DESC';
                    break;
                case 'script':
                    $orderBy = 'script_name ' . $dir . ', created_at DESC';
                    break;
                default:
                    $orderBy = 'created_at ' . $dir;
                    break;
            }
        }
    }

    return $orderBy;
}

return $server;
