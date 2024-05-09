<?php
    require_once("config.php"); // Include the configuration file

    // コマンドライン引数から環境を取得する 
    if (isset($argv[1])) {
        $environment = strtolower($argv[1]);
    } else {
        die("Usage: php main.php [environment]\n");
    }

    // 指定された環境に応じて設定を選択する
    if ($environment === "test") {
        $api_key = $test_api_key;
        $secret_key = $test_secret_key;
        $url = $test_url;
    } else if ($environment === "prod") {
        $api_key = $prod_api_key;
        $secret_key = $prod_secret_key;
        $url = $prod_url;
    } else {
        die("Invalid environment specified. Please use 'test' or 'prod'.\n");
    }

    function http_req($endpoint,$method,$params,$Info){
        global $api_key, $secret_key, $url;
        $timestamp = time() * 1000;
        $params_for_signature= $timestamp . $api_key . "5000" . $params;
        $signature = hash_hmac('sha256', $params_for_signature, $secret_key);
        $curl = curl_init(); // Initialize curl resource
        if($method=="GET") {
            $endpoint=$endpoint . "?" . $params;
        }
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_HTTPHEADER => array(
                "X-BAPI-API-KEY: $api_key",
                "X-BAPI-SIGN: $signature",
                "X-BAPI-SIGN-TYPE: 2",
                "X-BAPI-TIMESTAMP: $timestamp",
                "X-BAPI-RECV-WINDOW: 5000",
                "Content-Type: application/json"
            ),
        ));
        if($method=="GET") {
            curl_setopt($curl, CURLOPT_HTTPGET, true);
        }
        echo $Info . "\n";
        $response = curl_exec($curl);
        // echo $response . "\n";
        curl_close($curl); // Close curl resource
        return json_decode($response, true);
    }

    function get_spot_price($symbol) {
        $endpoint="/v5/market/kline";
        $method="GET";
        $params="category=spot&symbol=$symbol&interval=1";
        $response = http_req($endpoint, $method, $params, "Get Spot Price");
        return $response['result']['list'][0][4];
    }

    function create_future_order($spot_price, $qty, $symbol) {
        $endpoint="/v5/order/create";
        $method="POST";
        $orderLinkId=uniqid();
        $price = $spot_price * 0.996; // 0.5% less than spot price
        echo "spot_price : " . $spot_price. "price : " . $price . "\n";
        $params='{"category":"linear","symbol": "' . $symbol . '","side": "Buy","positionIdx": 0,"orderType": "Limit","qty": "' . $qty . '","price": "' . $price . '","timeInForce": "GTC","orderLinkId": "' . $orderLinkId . '"}';
        http_req($endpoint,$method,$params,"Create Future Order");
        return array("orderLinkId" => $orderLinkId); // Return orderLinkId and price
    }

    function check_order_status_executio($orderLinkId, $qty, $symbol) {
        $endpoint="/v5/execution/list";
        $method="GET";
        $params="category=linear&symbol=$symbol&orderLinkId=$orderLinkId";   
        $response = http_req($endpoint, $method, $params, "Check Order Status Executio");
        print_r($response);
        // var_dump($response);
        
        $orders = $response['result']['list'];
        if (!empty($response['result']['list'])) {
            print_r("BBBBB");
            return array("position_qty" => $qty, "position_taken" => true);
        }
        if (!empty($response['result']['list'])) {
            foreach ($orders as $order) {
                // Check if orderLinkId matches and qty has decreased
                if ($order['orderLinkId'] === $orderLinkId && $order['qty'] < $qty) {
                    print_r("DDDDD");
                    $position_qty = $qty - $order['qty']; // Calculate position qty
                    return array("position_qty" => $position_qty, "position_taken" => true); // Return position qty and flag
                } 
                print_r("KKKKK");
            }
        }
        
        return array("position_qty" => 0, "position_taken" => false); // Return default values
    }
    function check_order_status($orderLinkId, $qty, $symbol) {
        $endpoint="/v5/order/realtime";
        $method="GET";
        $params="category=linear&symbol=$symbol&orderLinkId=$orderLinkId";   
        $response = http_req($endpoint, $method, $params, "Check Order Status");
        print_r($response);
        
        $orders = $response['result']['list'];
        if (empty($response['result']['list'])) {
            print_r("BBBBB");
            return array("position_qty" => $qty, "position_taken" => true);
        }
        foreach ($orders as $order) {
            // Check if orderLinkId matches and qty has decreased
            if ($order['orderLinkId'] === $orderLinkId && $order['qty'] < $qty) {
                print_r("DDDDD");
                $position_qty = $qty - $order['qty']; // Calculate position qty
                return array("position_qty" => $position_qty, "position_taken" => true); // Return position qty and flag
            } 
            print_r("KKKKK");
        }    
        
        return array("position_qty" => 0, "position_taken" => false); // Return default values
    }

    function execute_spot_market_buy($qty, $symbol) {
        echo "execute_spot_market_buy" . "\n";
        $endpoint="/v5/order/create";
        $method="POST";
        $orderLinkId=uniqid();
        $params='{"category":"spot","symbol": "' . $symbol . '","side": "Sell","positionIdx": 0,"orderType": "Market","qty": "' . $qty . '","marketUnit":"baseCoin","timeInForce": "IOC","orderLinkId": "' . $orderLinkId . '"}';
        http_req("$endpoint","$method","$params","Create Order");
        // Spot buy logic using $qty
    }

    function cancel_future_order($orderLinkId, $symbol) {
        $endpoint="/v5/order/cancel";
        $method="POST";
        $params='{"category":"linear","symbol": "' . $symbol . '","orderLinkId": "' . $orderLinkId . '"}';
        http_req($endpoint,$method,$params,"Cancel Future Order");
    }

    // Main Loop
    while (true) {
        $symbol = "MNTUSDT"; // Set your desired qty here
        $spot_price = get_spot_price($symbol);
        $qty = 200000; // Set your desired qty here
        $future_order_info = create_future_order($spot_price, $qty, $symbol);
        $orderLinkId = $future_order_info["orderLinkId"];
        
        // Loop until the order is filled or 1 hour passes
        $start_time = time();
        while (true) {
            $order_status = check_order_status_executio($orderLinkId, $qty, $symbol);
            if ($order_status["position_taken"]) {
                execute_spot_market_buy($order_status["position_qty"], $symbol);
                break 2; // Break out of both loops
            } else {
                // Check if 1 hour has passed
                if (time() - $start_time >= 10) {
                    cancel_future_order($orderLinkId, $symbol);
                    break; // Break out of inner loop   
                }
                // Sleep for a short time before checking order status again
                sleep(1); // Sleep for 10 seconds
            }
        }
    }
    ?>
