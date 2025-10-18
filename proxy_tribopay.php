<?php
// TRIBOPAY - PROXY DE CHECKOUT V1.1 (Correção do campo 'installments')

// --- CONFIGURAÇÃO INICIAL ---
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// --- VERIFICAÇÃO CRÍTICA DE AMBIENTE ---
if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode(['error' => 'A extensão cURL do PHP não está ativada. Verifique as configurações do seu servidor.']);
    exit;
}

// --- SUAS CREDENCIAIS E CONFIGURAÇÕES DA TRIBOPAY ---
// ATENÇÃO: As credenciais abaixo são da ParadisePag e NÃO VÃO FUNCIONAR.
// Você PRECISA substituí-las pelas credenciais corretas do seu painel da TRIBOPAY.
$API_TOKEN      = 'Ziaznf07dS3qEH8kW9zLgXi5bs8u1MfUmdEFBOfr8TDvAWRYoA1WyVgjYHuz'; // <-- TROCAR PELO TOKEN DA TRIBOPAY
$OFFER_HASH     = 'shk1o2qtlc'; // <-- TROCAR PELO HASH DE OFERTA DA TRIBOPAY
$PRODUCT_HASH   = '9wwmwion1j'; // <-- TROCAR PELO HASH DE PRODUTO DA TRIBOPAY
$PRODUCT_TITLE  = 'Doação Paróquia São Domingos';
$BASE_AMOUNT    = 4990; // R$ 49,90 em centavos

// Função reutilizável para fazer chamadas cURL
function make_curl_request($url, $method = 'GET', $payload = null) {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers
    ];

    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        if ($payload) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
    }
    
    curl_setopt_array($ch, $options);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['error' => 'Erro de cURL: ' . $curl_error, 'http_code' => 0, 'response' => null];
    }
    
    return ['error' => null, 'http_code' => $http_code, 'response' => $response];
}

// --- ROTA 1: VERIFICAR STATUS DO PAGAMENTO ---
if (isset($_GET['action']) && $_GET['action'] === 'check_status') {
    $hash = $_GET['hash'] ?? null;
    if (!$hash) {
        http_response_code(400);
        echo json_encode(['error' => 'Hash da transação não informado']);
        exit;
    }

    $status_url = 'https://api.tribopay.com.br/api/public/v1/transactions/' . urlencode($hash) . '?api_token=' . $API_TOKEN;
    $result = make_curl_request($status_url);

    if ($result['error']) {
        http_response_code(500);
        echo json_encode(['error' => $result['error']]);
        exit;
    }

    $data = json_decode($result['response'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(500);
        echo json_encode(['error' => 'A API de status retornou uma resposta inválida.', 'raw_response' => $result['response']]);
        exit;
    }

    http_response_code($result['http_code']);
    echo json_encode(['payment_status' => $data['payment_status'] ?? 'unknown']);
    exit;
}

// --- ROTA 2: CRIAR NOVA TRANSAÇÃO PIX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_data = json_decode(file_get_contents('php://input'), true);
    $customer_data = $input_data['customer'] ?? [];

    if (empty($customer_data) || empty($customer_data['name']) || empty($customer_data['email']) || empty($customer_data['phone_number'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Dados do cliente (nome, e-mail, telefone) são obrigatórios.']);
        exit;
    }

    $payload = [
        'amount' => $BASE_AMOUNT,
        'offer_hash' => $OFFER_HASH,
        'payment_method' => 'pix',
        'installments' => 1, // <-- CORREÇÃO ADICIONADA AQUI
        'customer' => [
            'name' => $customer_data['name'],
            'email' => $customer_data['email'],
            'phone_number' => preg_replace('/\D/', '', $customer_data['phone_number']),
            'document' => preg_replace('/\D/', '', $customer_data['document'])
        ],
        'cart' => [[
            'product_hash' => $PRODUCT_HASH,
            'title' => $PRODUCT_TITLE,
            'price' => $BASE_AMOUNT,
            'quantity' => 1,
            'operation_type' => 1,
            'tangible' => false
        ]]
    ];

    $api_url = 'https://api.tribopay.com.br/api/public/v1/transactions?api_token=' . $API_TOKEN;
    $result = make_curl_request($api_url, 'POST', $payload);

    if ($result['error']) {
        http_response_code(500);
        echo json_encode(['error' => $result['error']]);
        exit;
    }
    
    json_decode($result['response']);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(500);
        echo json_encode(['error' => 'A API de criação de transação retornou uma resposta inválida.', 'http_code' => $result['http_code'], 'raw_response' => $result['response']]);
        exit;
    }

    http_response_code($result['http_code']);
    echo $result['response'];
    exit;
}

// Se nenhum método corresponder, retorna erro.
http_response_code(405);
echo json_encode(['error' => 'Método de requisição não permitido.']);
?>