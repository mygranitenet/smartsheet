<?php
// --- Configuration, Smartsheet Functions, Gemini Prompt Function ---
// (These are the same as the previous full script version)
// Ensure SMARTSHEET_ACCESS_TOKEN and GEMINI_API_KEY are set

const SMARTSHEET_ACCESS_TOKEN = 'WRCMeuYeC0iI88FuhI72NFvKXOFtqcsnv1VFz';
const GEMINI_API_KEY = 'AIzaSyBTZB91O6J98CBCr2H0Ij7WHLOl9J1UM5w';
const GEMINI_API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . GEMINI_API_KEY;
const TARGET_COLUMN_TITLES = [
    "Combined", "Site ID", "Status", "GRT Ticket #", "Assignment ID",
    "Link", "CW Status", "Schedule Date", "Rollout Quote #","Summary Description"
];

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(900);
$sheetInfoCache = [];
session_start();

// --- Smartsheet API Functions (fetchSmartsheetAPI, getDecodedApiResponse, getSheetConfig, fetchAllRowsFromSheet, fetchDiscussionsForRow, processSheetAndItsRows) ---
// ... (These are the same as the previous full script. Ensure they are included) ...
function fetchSmartsheetAPI(string $apiUrl, string $accessToken): array { /* ... */
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer " . $accessToken, "Accept: application/json"],
        CURLOPT_FAILONERROR => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 120
    ]);
    $responseBody = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrorNum = curl_errno($ch); $curlError = curl_error($ch); curl_close($ch);
    if ($curlErrorNum > 0) { error_log("Smartsheet cURL Error for $apiUrl: $curlError (Errno: $curlErrorNum)");}
    if ($httpCode !== 200 && $httpCode !== 404) {error_log("Smartsheet API Error for $apiUrl: HTTP $httpCode - Body: ".substr($responseBody,0,500));}
    return ['httpCode' => $httpCode, 'responseBody' => $responseBody, 'curlErrorNum' => $curlErrorNum, 'curlError' => $curlError];
 }

function getDecodedApiResponse(array $apiResult, string $contextErrorMessage = "Smartsheet API Error", bool $haltOnError = true): ?array { /* ... */
    if ($apiResult['curlErrorNum'] > 0) {
        error_log("Smartsheet API cURL Error: {$contextErrorMessage} - Errno: {$apiResult['curlErrorNum']}, Error: {$apiResult['curlError']}");
        return ['script_error' => true, 'message' => "API Communication Error", 'context' => $contextErrorMessage, 'details' => "cURL Error: " . $apiResult['curlError']];
    }
    $decodedBody = json_decode($apiResult['responseBody'], true);
    if ($apiResult['httpCode'] == 200) {
        if (json_last_error() === JSON_ERROR_NONE) return $decodedBody;
        error_log("Smartsheet API JSON Decode Error: {$contextErrorMessage} - Msg: " . json_last_error_msg() . " - Snippet: " . substr($apiResult['responseBody'],0,200));
        return ['script_error' => true, 'message' => "API Response Format Error", 'context' => $contextErrorMessage, 'details' => json_last_error_msg()];
    }
    if ($apiResult['httpCode'] == 404 && !$haltOnError) {
        error_log("Smartsheet API Info (404 Not Found): {$contextErrorMessage}");
        return null;
    }
    error_log("Smartsheet API Error ({$apiResult['httpCode']}): {$contextErrorMessage} - Body: " . $apiResult['responseBody']);
    return ['script_error' => true, 'message' => "Smartsheet API Error", 'http_code' => $apiResult['httpCode'], 'details' => $decodedBody ?? ['raw_response' => $apiResult['responseBody']], 'context' => $contextErrorMessage ];
 }

function getSheetConfig(string $sheetId, string $accessToken): ?array { /* ... */
    global $sheetInfoCache;
    if (isset($sheetInfoCache[$sheetId])) return $sheetInfoCache[$sheetId];
    $apiUrl = "https://api.smartsheet.com/2.0/sheets/{$sheetId}?pageSize=1&page=1&include=objectValue";
    $apiResult = fetchSmartsheetAPI($apiUrl, $accessToken);
    $sheetData = getDecodedApiResponse($apiResult, "sheet ID {$sheetId} (getSheetConfig)");
    if (isset($sheetData['script_error'])) return $sheetData;

    if ($sheetData && isset($sheetData['columns']) && is_array($sheetData['columns'])) {
        $targetColumnIdToSafeKeyMap = [];
        foreach ($sheetData['columns'] as $column) {
            if (in_array($column['title'], TARGET_COLUMN_TITLES)) {
                $safeKey = strtolower(str_replace([' ', '#', '/'], ['_', '', '_'], $column['title']));
                $targetColumnIdToSafeKeyMap[$column['id']] = $safeKey;
            }
        }
        $foundSafeKeys = array_values($targetColumnIdToSafeKeyMap);
        foreach (TARGET_COLUMN_TITLES as $title) {
            $expectedSafeKey = strtolower(str_replace([' ', '#', '/'], ['_', '', '_'], $title));
            if (!in_array($expectedSafeKey, $foundSafeKeys) && !empty(TARGET_COLUMN_TITLES) && !empty($targetColumnIdToSafeKeyMap)) {
                error_log("Target column '{$title}' (expected key: {$expectedSafeKey}) not found or ID not mapped in sheet ID {$sheetId}.");
            }
        }
        $sheetName = $sheetData['name'] ?? ('Sheet ' . $sheetId);
        $sheetInfoCache[$sheetId] = [
            'name' => $sheetName,
            'columnIdToSafeKeyMap' => $targetColumnIdToSafeKeyMap
        ];
        return $sheetInfoCache[$sheetId];
    }
    error_log("Could not get column config for sheet ID {$sheetId}. SheetData: " . print_r($sheetData, true));
    return ['script_error' => true, 'message' => "Could not get column config for sheet {$sheetId}", 'sheet_id' => $sheetId];
 }

function fetchAllRowsFromSheet(string $sheetId, string $accessToken): Generator { /* ... */
    $currentPage = 1; $totalPages = 1; $pageSize = 100;
    do {
        $apiUrl = "https://api.smartsheet.com/2.0/sheets/{$sheetId}?page={$currentPage}&pageSize={$pageSize}&include=objectValue";
        $apiResult = fetchSmartsheetAPI($apiUrl, $accessToken);
        $sheetPageData = getDecodedApiResponse($apiResult, "sheet ID {$sheetId}, page {$currentPage} (fetchAllRowsFromSheet)");
        if (isset($sheetPageData['script_error'])) { yield ['row_fetch_error' => $sheetPageData]; break; }

        if ($sheetPageData && isset($sheetPageData['rows']) && is_array($sheetPageData['rows'])) {
            foreach ($sheetPageData['rows'] as $row) { yield $row; }
            if ($currentPage === 1 && isset($sheetPageData['totalPages'])) { $totalPages = $sheetPageData['totalPages']; }
            elseif (!isset($sheetPageData['totalPages']) && $currentPage === 1) {
                $totalPages = (count($sheetPageData['rows']) < $pageSize && isset($sheetPageData['totalRowCount']) && $sheetPageData['totalRowCount'] <= $pageSize) ? 1 : ($currentPage + (empty($sheetPageData['rows']) ? 0 : 1));
            }
            $currentPage++;
        } else {
            error_log("No 'rows' in Get Sheet response for sheet {$sheetId}, page {$currentPage}. API Result: ".print_r($apiResult, true));
            break;
        }
    } while ($currentPage <= $totalPages);
 }

function fetchDiscussionsForRow(string $sheetId, string $rowId, string $accessToken): array { /* ... */
    $allRowDiscussions = []; $currentPage = 1; $totalPages = 1; $pageSize = 100;
    do {
        $apiUrl = "https://api.smartsheet.com/2.0/sheets/{$sheetId}/rows/{$rowId}/discussions?page={$currentPage}&pageSize={$pageSize}&include=comments";
        $apiResult = fetchSmartsheetAPI($apiUrl, $accessToken);
        $pageData = getDecodedApiResponse($apiResult, "discussions for row {$rowId} on sheet {$sheetId}, page {$currentPage}", false);
        if (isset($pageData['script_error'])) { return [['discussion_fetch_error' => $pageData]]; }

        if ($pageData && isset($pageData['data']) && is_array($pageData['data'])) {
            $allRowDiscussions = array_merge($allRowDiscussions, $pageData['data']);
            $totalPages = $pageData['totalPages'] ?? 1; $currentPage++;
        } elseif ($apiResult['httpCode'] == 404) { break;
        } elseif ($pageData && !isset($pageData['data'])) {
            if ($totalPages === 1 && $currentPage === 1) break;
            $currentPage++;
        } else { error_log("Unexpected issue fetching discussions for row {$rowId}, sheet {$sheetId}."); break; }
    } while ($currentPage <= $totalPages);
    return $allRowDiscussions;
 }

function processSheetAndItsRows($sheetId, $accessToken) { /* ... */
    $sheetConfig = getSheetConfig($sheetId, $accessToken);
    if (isset($sheetConfig['script_error']) || !$sheetConfig) {
        if (!$sheetConfig) { return ['error' => true, 'message' => "Critical error: Sheet configuration is null for sheet " . htmlspecialchars($sheetId), 'sheet_id' => htmlspecialchars($sheetId)]; }
        return $sheetConfig;
    }
    $sheetName = $sheetConfig['name'];
    $columnIdToSafeKeyMap = $sheetConfig['columnIdToSafeKeyMap'];
    $outputDataForRule = []; $totalRowsScanned = 0; $totalDiscussionsOverall = 0; $totalCommentsExtracted = 0;
    $rowsGenerator = fetchAllRowsFromSheet($sheetId, $accessToken);
    foreach ($rowsGenerator as $row) {
        if (isset($row['row_fetch_error'])) {
             error_log("Error fetching rows for sheet {$sheetId}: " . json_encode($row['row_fetch_error']));
             return ['error' => true, 'message' => "Error fetching rows for sheet " . htmlspecialchars($sheetId), 'details' => $row['row_fetch_error'], 'sheet_id' => htmlspecialchars($sheetId)];
        }
        $totalRowsScanned++;
        if (!isset($row['id'])) { error_log("Skipping row, missing ID: sheet {$sheetId}"); continue; }
        $rowId = (string) $row['id'];
        $currentRowColumnValues = [];
        foreach (TARGET_COLUMN_TITLES as $title) {
            $safeKey = strtolower(str_replace([' ', '#', '/'], ['_', '', '_'], $title));
            $currentRowColumnValues[$safeKey] = "N/A";
        }
        if (isset($row['cells']) && is_array($row['cells'])) {
            foreach ($row['cells'] as $cell) {
                if (isset($columnIdToSafeKeyMap[$cell['columnId']])) {
                    $safeKey = $columnIdToSafeKeyMap[$cell['columnId']];
                    $currentRowColumnValues[$safeKey] = (string) ($cell['displayValue'] ?? ($cell['value'] ?? "N/A"));
                }
            }
        }
        $discussionsOnThisRow = fetchDiscussionsForRow($sheetId, $rowId, $accessToken);
        if (isset($discussionsOnThisRow[0]['discussion_fetch_error'])) {
            error_log("Error fetching discussions for row {$rowId} on sheet {$sheetId}: " . json_encode($discussionsOnThisRow[0]['discussion_fetch_error']));
        }
        $currentDiscussionCount = (is_array($discussionsOnThisRow) && !isset($discussionsOnThisRow[0]['discussion_fetch_error'])) ? count($discussionsOnThisRow) : 0;
        $totalDiscussionsOverall += $currentDiscussionCount;
        $commentsForRow = [];
        if ($currentDiscussionCount > 0) {
            foreach ($discussionsOnThisRow as $discussion) {
                if (isset($discussion['comments']) && is_array($discussion['comments']) && !empty($discussion['comments'])) {
                    foreach ($discussion['comments'] as $comment) {
                        $totalCommentsExtracted++;
                        $commentsForRow[] = [
                            'disc_id' => $discussion['id'] ?? 'N/A', 'comm_id' => $comment['id'] ?? 'N/A',
                            'text' => $comment['text'] ?? '', 'author_name' => $comment['createdBy']['name'] ?? 'N/A',
                            'author_email' => $comment['createdBy']['email'] ?? 'N/A',
                            'created' => $comment['createdAt'] ?? '', 'modified' => $comment['modifiedAt'] ?? ''
                        ];}}}}
        $rowDataEntry = ['row_id' => $rowId, 'row_number' => $row['rowNumber'] ?? 'N/A'];
        $rowDataEntry = array_merge($rowDataEntry, $currentRowColumnValues);
        $rowDataEntry['comments_on_row'] = $commentsForRow;
        $outputDataForRule[] = $rowDataEntry;
    }
    return [
        'sheet' => ['id' => htmlspecialchars($sheetId), 'name' => htmlspecialchars($sheetName)],
        'summary' => [
            'total_rows_scanned' => $totalRowsScanned,
            'total_discussions_found_on_sheet' => $totalDiscussionsOverall,
            'total_comments_extracted' => $totalCommentsExtracted
        ],
        'rows_data' => $outputDataForRule];
 }


// --- Gemini API Function (this will be called by gemini_chat_api.php for follow-ups) ---
// We also need it here for the initial call.
function callGeminiAPI(array $conversationHistory, string $apiKey, string $apiUrl): array {
    $payload = ['contents' => $conversationHistory];
    // It's good practice to add generationConfig for safety, though not strictly required for basic chat
    $payload['generationConfig'] = [
        "temperature" => 0.7, // Controls randomness. Lower is more deterministic.
        "topK" => 1,
        "topP" => 1,
        "maxOutputTokens" => 8192, // Max output tokens for gemini-1.5-flash
        // "stopSequences" => [], // Optional: sequences where the model should stop generating
    ];
    // Add safety settings - adjust these based on your content policy needs
    $payload['safetySettings'] = [
        ["category" => "HARM_CATEGORY_HARASSMENT", "threshold" => "BLOCK_MEDIUM_AND_ABOVE"],
        ["category" => "HARM_CATEGORY_HATE_SPEECH", "threshold" => "BLOCK_MEDIUM_AND_ABOVE"],
        ["category" => "HARM_CATEGORY_SEXUALLY_EXPLICIT", "threshold" => "BLOCK_MEDIUM_AND_ABOVE"],
        ["category" => "HARM_CATEGORY_DANGEROUS_CONTENT", "threshold" => "BLOCK_MEDIUM_AND_ABOVE"],
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl, CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_TIMEOUT => 240 // Increased timeout for Gemini
    ]);
    $responseBody = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrorNum = curl_errno($ch); $curlError = curl_error($ch); curl_close($ch);

    if ($curlErrorNum > 0) {
        error_log("Gemini API cURL Error: {$curlError} (Errno: {$curlErrorNum})");
        return ['error' => true, 'message' => "Gemini API Communication Error: " . $curlError, 'raw_response_body' => $responseBody];
    }
    $decodedResponse = json_decode($responseBody, true);

    if ($httpCode === 200) {
        if (isset($decodedResponse['candidates'][0]['content']['parts'][0]['text'])) {
            return ['error' => false, 'text' => $decodedResponse['candidates'][0]['content']['parts'][0]['text'], 'raw_api_response' => $decodedResponse];
        } elseif (isset($decodedResponse['candidates'][0]['finishReason']) && $decodedResponse['candidates'][0]['finishReason'] !== 'STOP') {
            // Handle cases where generation stopped due to safety or other reasons
            $reason = $decodedResponse['candidates'][0]['finishReason'];
            $safetyRatings = $decodedResponse['candidates'][0]['safetyRatings'] ?? [];
            error_log("Gemini API generation stopped. Reason: {$reason}. Safety Ratings: " . print_r($safetyRatings, true));
            $errorMessage = "Gemini response generation stopped due to: {$reason}.";
            if (!empty($safetyRatings)) {
                foreach($safetyRatings as $rating) {
                    if ($rating['probability'] !== 'NEGLIGIBLE') {
                         $errorMessage .= " Potential safety concern: " . $rating['category'] . " (" . $rating['probability'] . ").";
                    }
                }
            }
            return ['error' => true, 'message' => $errorMessage, 'details' => $decodedResponse, 'raw_response_body' => $responseBody];
        }
    }
    // Fallback for other errors or unexpected 200 OK responses
    if (isset($decodedResponse['error'])) {
         error_log("Gemini API Error (HTTP {$httpCode}) in payload: " . print_r($decodedResponse['error'], true));
         return ['error' => true, 'message' => "Gemini API Error: " . ($decodedResponse['error']['message'] ?? 'Unknown error in payload'), 'details' => $decodedResponse, 'raw_response_body' => $responseBody];
    }
    error_log("Gemini API Error (HTTP {$httpCode}) or unexpected response: " . $responseBody);
    return ['error' => true, 'message' => "Gemini API Error (HTTP {$httpCode}) or unexpected response", 'details' => $decodedResponse ?? ['raw_response' => $responseBody], 'raw_response_body' => $responseBody];
}


// --- Function to construct the detailed Gemini prompt ---
function constructGeminiExecutivePromptBase(): string {
    $reportingDate = date('Y-m-d'); // Gets today's date in YYYY-MM-DD format
    $formattedDate = date('m/d/Y'); // For use in the prompt text (e.g., 06/24/2025)

    $prompt = "You are an AI assistant tasked with extracting and summarizing project information for an executive report.\n\n";
    $prompt .= "Input Data:\n";
    $prompt .= "You will be provided with JSON data. This data contains an array of objects, where each object represents a \"sheet\". Each sheet object has a 'sheet' info part, a 'summary' of processing, and a 'rows_data' array. Each item in 'rows_data' represents a site visit and includes various extracted columns (like 'combined', 'site_id', 'status', etc.) and a 'comments_on_row' array. Each comment has 'text', 'author_name', and 'created' timestamp.\n\n";
    $prompt .= "Reporting Date:\n";
    $prompt .= "Assume the current reporting date is $reportingDate.\n\n";
    $prompt .= "Task:\n";
    $prompt .= "Based on the provided JSON data and the reporting date, populate ONLY the following fields. Ensure your output is concise, professional, and suitable for an executive audience.\n\n";
    $prompt .= "Fields to Populate:\n\n";
    $prompt .= "Notes:\n";
    $prompt .= "Provide a summary of recent activities and key findings, primarily focusing on the last month of data (approx. mid-month prior to mid-this-month), but also include overarching trends if significant. Highlight common reasons for delays or revisits. Mention any significant positive or negative developments. Organize information thematically or chronologically. Start with the reporting date (e.g., \"$formattedDate (Summary of recent activity):\").\n\n";
    $prompt .= "Number of Sites Completed:\n";
    $prompt .= "Carefully count the number of unique sites (based on row_id or a combination of site_id and other unique identifiers from the row's column data) that have a final status indicating completion. \"Completion\" statuses typically include \"BILLED - COMP\" or \"RDY 2 BILL - COMP\" in the 'combined' column value or clearly stated in the latest comment text for that site/row. Crucial: If a site appears multiple times (e.g. across different sheets or rows if site_id is the key), use the latest timestamped comment/status for that site to determine its true final status. Provide only the numerical count.\n\n";
    $prompt .= "Run Rate(# of sites per week):\n";
    $prompt .= "Calculate this based on: The \"Number of Sites Completed\" (derived above). The project start date: Determine this from the earliest \"created\" timestamp in the entire comment dataset. The \"Reporting Date\" ($reportingDate). Formula: Run Rate = (Total Sites Completed) / ((Reporting Date - Project Start Date in days) / 7). Round to the nearest whole number or one decimal place (e.g., \"~5 sites/week \").\n\n";
    $prompt .= "Output Format:\n";
    $prompt .= "Present the information for each field clearly. You can use a key-value format or list each field and its corresponding value.\n\n";
    $prompt .= "Here is the Data (JSON format):\n\n";

    return $prompt;
}


// --- Main Logic: Form Submission or Display Form ---
$smartsheetJsonForDisplay = null;
$errorMessage = null;
$inputSheetIds = $_GET['sheet_ids'] ?? ($_SESSION['inputSheetIds'] ?? '');
$editableSystemPrompt = $_SESSION['editableSystemPrompt'] ?? constructGeminiExecutivePromptBase(); // Load from session or default

if (!isset($_SESSION['geminiConversation'])) { $_SESSION['geminiConversation'] = []; }

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['process_data'])) {
        $_SESSION['geminiConversation'] = [];
        $_SESSION['smartsheetJsonForDisplay'] = null;
        $inputSheetIds = trim($_POST['sheet_ids'] ?? '');
        $uploadedFileContent = null;
        $dataSourceUsed = null;
        $userEditedSystemPrompt = trim($_POST['system_prompt'] ?? constructGeminiExecutivePromptBase());
        $_SESSION['editableSystemPrompt'] = $userEditedSystemPrompt; // Save edited prompt for next page load

        // File Upload or Smartsheet Fetch Logic (same as before)
        if (isset($_FILES['data_file']) && $_FILES['data_file']['error'] == UPLOAD_ERR_OK && $_FILES['data_file']['size'] > 0) {
            // ... (file upload handling as before) ...
            $tmpName = $_FILES['data_file']['tmp_name'];
            $fileType = mime_content_type($tmpName);
            $allowedTypes = ['text/plain', 'application/json'];
            if (in_array($fileType, $allowedTypes)) {
                if ($_FILES['data_file']['size'] > 10 * 1024 * 1024) { $errorMessage = "Uploaded file is too large (max 10MB)."; }
                else {
                    $uploadedFileContent = file_get_contents($tmpName);
                    json_decode($uploadedFileContent);
                    if (json_last_error() !== JSON_ERROR_NONE) { $errorMessage = "Uploaded file is not valid JSON. Error: " . json_last_error_msg(); $uploadedFileContent = null; }
                    else {
                        $dataSourceUsed = "Uploaded File";
                        $_SESSION['smartsheetJsonForDisplay'] = json_encode(json_decode($uploadedFileContent), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }
                }
            } else { $errorMessage = "Invalid file type. Please upload a .txt or .json file."; }
        } elseif (!empty($inputSheetIds) && defined('SMARTSHEET_ACCESS_TOKEN') && SMARTSHEET_ACCESS_TOKEN !== 'YOUR_NEW_VALID_SMARTSHEET_TOKEN_HERE' && SMARTSHEET_ACCESS_TOKEN !== '') {
            $_SESSION['inputSheetIds'] = $inputSheetIds;
            $sheetIds = array_map('trim', explode(',', $inputSheetIds));
            $allSheetsResults = []; $hasCriticalError = false;
            foreach ($sheetIds as $sheetId) { /* ... Smartsheet fetching ... */
                if (!ctype_digit($sheetId) || $sheetId <= 0) { $allSheetsResults[] = ['error' => true, 'message' => "Invalid Sheet ID format: " . htmlspecialchars($sheetId), 'sheet_id_provided' => htmlspecialchars($sheetId)]; continue; }
                $result = processSheetAndItsRows($sheetId, SMARTSHEET_ACCESS_TOKEN);
                if (isset($result['error']) && $result['error'] === true) { $errorMessage = "Error processing sheet " . htmlspecialchars($sheetId) . ": " . ($result['message'] ?? 'Unknown error'); $allSheetsResults[] = $result; $hasCriticalError = true; break; }
                $allSheetsResults[] = $result;
            }
            if (!$hasCriticalError) {
                $uploadedFileContent = json_encode($allSheetsResults, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $_SESSION['smartsheetJsonForDisplay'] = json_encode($allSheetsResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $dataSourceUsed = "Smartsheet API (IDs: " . htmlspecialchars($inputSheetIds) . ")";
            } else { $_SESSION['smartsheetJsonForDisplay'] = json_encode($allSheetsResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
        } else { /* ... error handling for no data source ... */
            if (empty($inputSheetIds) && (!isset($_FILES['data_file']) || $_FILES['data_file']['error'] != UPLOAD_ERR_OK || $_FILES['data_file']['size'] == 0) ) {
                 $errorMessage = "Please enter Sheet IDs OR upload a data file.";
            } else if (!empty($inputSheetIds)) {
                 $errorMessage = 'CRITICAL: Smartsheet API Access Token is not configured or is invalid for fetching live data.';
            }
        }

        if ($uploadedFileContent && !$errorMessage) {
            if (GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE' || empty(GEMINI_API_KEY)) {
                $errorMessage = "Gemini API Key is not configured.";
            } else {
                // Use the (potentially edited) system prompt from the form
                $initialFullPromptWithData = $userEditedSystemPrompt . $uploadedFileContent;
                $_SESSION['initialFullPromptWithData_for_display_logic'] = $initialFullPromptWithData; // For hiding logic

                $_SESSION['geminiConversation'][] = ['role' => 'user', 'parts' => [['text' => $initialFullPromptWithData]]];
                $geminiResult = callGeminiAPI($_SESSION['geminiConversation'], GEMINI_API_KEY, GEMINI_API_URL);
                if (!$geminiResult['error']) {
                    $_SESSION['geminiConversation'][] = ['role' => 'model', 'parts' => [['text' => $geminiResult['text']]]];
                } else {
                    $errorMessage = "Error from Gemini API: " . ($geminiResult['message'] ?? 'Unknown error');
                    if (isset($geminiResult['details']['error']['message'])) { $errorMessage .= " Details: " . $geminiResult['details']['error']['message']; }
                    array_pop($_SESSION['geminiConversation']);
                }
            }
        } elseif (!$errorMessage && empty($uploadedFileContent)) {
             if (empty($inputSheetIds) && !(isset($_FILES['data_file']) && $_FILES['data_file']['error'] == UPLOAD_ERR_OK && $_FILES['data_file']['size'] > 0) ) {
                 // This condition was already checked, but as a fallback.
                 // $errorMessage = "No data source provided to send to Gemini.";
            }
        }
    } elseif (isset($_POST['reset_chat'])) {
        $_SESSION['geminiConversation'] = [];
        $_SESSION['smartsheetJsonForDisplay'] = null;
        $_SESSION['initialFullPromptWithData_for_display_logic'] = null;
        $_SESSION['inputSheetIds'] = ''; $inputSheetIds = '';
        $_SESSION['editableSystemPrompt'] = constructGeminiExecutivePromptBase(); // Reset prompt to default
        $editableSystemPrompt = $_SESSION['editableSystemPrompt'];
    }
    // The 'send_to_gemini' POST action is now handled by gemini_chat_api.php via JavaScript
} else { // GET request
    $smartsheetJsonForDisplay = $_SESSION['smartsheetJsonForDisplay'] ?? null;
    $inputSheetIds = $_SESSION['inputSheetIds'] ?? '';
    $editableSystemPrompt = $_SESSION['editableSystemPrompt'] ?? constructGeminiExecutivePromptBase();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smartsheet/File Data to Gemini Executive Chat</title>
    <style>
        /* ... (CSS styles from previous version - keep them) ... */
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol"; margin: 0; padding: 0; background-color: #f0f2f5; color: #333; display: flex; flex-direction: column; min-height: 100vh; font-size: 16px; line-height: 1.6;}
        .container { max-width: 900px; margin: 20px auto; background-color: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); flex-grow: 1; display: flex; flex-direction: column;}
        h1, h2, h3 { color: #1c3d5a; }
        h1 { text-align: center; margin-bottom: 30px; font-size: 2em;}
        h2 { font-size: 1.5em; margin-top: 30px; margin-bottom: 15px; border-bottom: 2px solid #e0e0e0; padding-bottom: 10px;}
        h3 { font-size: 1.2em; margin-top: 25px; margin-bottom: 10px; color: #005a9e;}
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #455a64; }
        input[type="text"], textarea, input[type="file"] { width: 100%; padding: 12px 15px; margin-bottom: 20px; border: 1px solid #ccd1d9; border-radius: 8px; box-sizing: border-box; font-size: 1em; transition: border-color 0.2s, box-shadow 0.2s; }
        input[type="text"]:focus, textarea:focus, input[type="file"]:focus { border-color: #0078D4; box-shadow: 0 0 0 2px rgba(0, 120, 212, 0.2); outline: none; }
        input[type="file"] { padding: 8px 15px; }
        textarea { min-height: 120px; resize: vertical; }
        #system_prompt_editor { min-height: 200px; font-family: monospace; font-size: 0.9em;}
        input[type="submit"], button { background-color: #0078D4; color: white; padding: 12px 25px; border: none; border-radius: 8px; cursor: pointer; font-size: 1em; font-weight: 500; transition: background-color 0.2s, transform 0.1s; }
        input[type="submit"]:hover, button:hover { background-color: #005a9e; }
        input[type="submit"]:active, button:active { transform: translateY(1px); }
        .button-secondary { background-color: #6c757d; }
        .button-secondary:hover { background-color: #545b62; }
        pre { background-color: #f8f9fa; border: 1px solid #e0e0e0; padding: 15px; border-radius: 8px; white-space: pre-wrap; word-wrap: break-word; max-height: 350px; overflow-y: auto; font-size: 0.85em; margin-bottom: 20px; line-height: 1.5; }
        .error { color: #c0392b; background-color: #fdedec; border: 1px solid #f5c6cb; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
        .chat-area { margin-top: 30px; border-top: 2px solid #e0e0e0; padding-top: 20px; flex-grow: 1; display: flex; flex-direction: column;}
        .conversation { flex-grow: 1; max-height: 50vh; overflow-y: auto; margin-bottom: 20px; border: 1px solid #e0e0e0; padding: 15px; border-radius: 8px; background-color: #fdfdfd;}
        .turn { margin-bottom: 18px; padding: 12px 15px; border-radius: 10px; line-height: 1.5; max-width: 85%; word-wrap: break-word; }
        .turn.user { background-color: #e7f3ff; text-align: left; margin-left: auto; border-bottom-right-radius: 0;}
        .turn.model { background-color: #f1f3f4; text-align: left; margin-right: auto; border-bottom-left-radius: 0;}
        .turn strong { display: block; margin-bottom: 6px; color: #005a9e; font-size: 0.9em; text-transform: uppercase; letter-spacing: 0.5px;}
        .form-section { margin-bottom: 30px; padding-bottom: 25px; border-bottom: 1px solid #eee;}
        .form-section:last-of-type { border-bottom: none; }
        .button-group { display: flex; gap: 10px; margin-top: 10px; }
        .data-source-option { margin-bottom: 15px; }
        #chat-form textarea { margin-bottom: 10px;}
    </style>
</head>
<body>
    <div class="container">
        <h1>Smartsheet/File Data » Gemini Executive Chat</h1>

        <?php if ($errorMessage): ?>
            <p class="error"><?php echo htmlspecialchars($errorMessage); ?></p>
        <?php endif; ?>

        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" enctype="multipart/form-data">
            <div class="form-section">
                <h2>1. Provide Data Source</h2>
                <div class="data-source-option">
                    <label for="sheet_ids">Option A: Smartsheet IDs (comma-separated):</label>
                    <input type="text" id="sheet_ids" name="sheet_ids" value="<?php echo htmlspecialchars($inputSheetIds); ?>" placeholder="e.g., 12345,67890 (leave blank if uploading file)">
                </div>
                <div class="data-source-option">
                    <label for="data_file">Option B: Upload Data File (e.g., .txt, .json):</label>
                    <input type="file" id="data_file" name="data_file" accept=".json,.txt">
                </div>
            </div>

            <div class="form-section">
                <h2>2. System Prompt for Gemini (Edit if needed)</h2>
                <label for="system_prompt_editor">Prompt Instructions:</label>
                <textarea id="system_prompt_editor" name="system_prompt"><?php echo htmlspecialchars($editableSystemPrompt); ?></textarea>
                <p style="font-size:0.8em; color:#555;">Note: The actual data (from Smartsheet or file) will be appended to this prompt when sent to Gemini.</p>
            </div>

            <div class="button-group">
                <input type="submit" name="process_data" value="Process Data & Start Chat">
                <button type="submit" name="reset_chat" class="button-secondary">Reset Chat & Data</button>
            </div>
        </form>

        <?php if (isset($_SESSION['smartsheetJsonForDisplay']) && $_SESSION['smartsheetJsonForDisplay']): ?>
            <div class="form-section">
                <h3>Data Used for Initial Gemini Prompt (JSON Preview)</h3>
                <pre id="smartsheet-data-display"><?php echo htmlspecialchars($_SESSION['smartsheetJsonForDisplay']); ?></pre>
            </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['geminiConversation'])): ?>
            <div class="chat-area">
                <h2>3. Chat with Gemini</h2>
                <div class="conversation" id="conversation-display">
                    <?php
                    $isFirstUserTurn = true;
                    $initialPromptTextInSession = $_SESSION['initialFullPromptWithData_for_display_logic'] ?? '';
                    foreach ($_SESSION['geminiConversation'] as $turn):
                        if ($turn['role'] === 'user' && $isFirstUserTurn && $turn['parts'][0]['text'] === $initialPromptTextInSession) {
                            $isFirstUserTurn = false;
                            echo '<div class="turn user"><strong>User:</strong> <em>(Initial detailed prompt with provided data was sent to Gemini)</em></div>';
                            continue;
                        }
                    ?>
                        <div class="turn <?php echo htmlspecialchars($turn['role']); ?>">
                            <strong><?php echo htmlspecialchars(ucfirst($turn['role'])); ?>:</strong>
                            <div><?php echo nl2br(htmlspecialchars($turn['parts'][0]['text'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <form id="chat-form">
                    <label for="user_query">Your follow-up query:</label>
                    <textarea id="user_query" name="user_query" placeholder="Ask Gemini to refine or analyze further..."></textarea>
                    <button type="submit" id="send-to-gemini-btn">Send to Gemini</button>
                </form>
            </div>
        <?php elseif ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['process_data']) && isset($_SESSION['initialFullPromptWithData_for_display_logic']) && !$errorMessage): ?>
             <p class="info" style="text-align:left; padding:10px; background-color:#eef; border-radius:6px;">Getting initial response from Gemini based on provided data and prompt...</p>
        <?php endif; ?>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatForm = document.getElementById('chat-form');
    const userQueryInput = document.getElementById('user_query');
    const conversationDisplay = document.getElementById('conversation-display');
    const sendButton = document.getElementById('send-to-gemini-btn');

    if (chatForm) {
        chatForm.addEventListener('submit', async function(event) {
            event.preventDefault();
            const userQuery = userQueryInput.value.trim();
            if (!userQuery) return;

            sendButton.disabled = true;
            sendButton.textContent = 'Sending...';
            appendMessageToChat('user', userQuery); // Display user message immediately
            userQueryInput.value = ''; // Clear input after getting value

            try {
                const response = await fetch('gemini_chat_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', },
                    body: JSON.stringify({ user_query: userQuery })
                });

                if (!response.ok) {
                    let errorText = `Server error: ${response.status} ${response.statusText}`;
                    try {
                        const errorData = await response.json();
                        errorText += `. ` + (errorData.message || JSON.stringify(errorData.details) || '');
                    } catch (e) { /* ignore if error response isn't json */ }
                    throw new Error(errorText);
                }

                const result = await response.json();

                if (result.error) {
                    appendMessageToChat('model', `Error: ${result.message} ${result.details ? JSON.stringify(result.details) : ''}`);
                } else {
                    appendMessageToChat('model', result.text);
                }

            } catch (error) {
                console.error('Fetch error:', error);
                appendMessageToChat('model', `Client-side error: ${error.message}`);
            } finally {
                sendButton.disabled = false;
                sendButton.textContent = 'Send to Gemini';
            }
        });
    }

    function appendMessageToChat(role, text) {
        if (!conversationDisplay) return;
        const turnDiv = document.createElement('div');
        turnDiv.classList.add('turn', role);

        const strong = document.createElement('strong');
        strong.textContent = role.charAt(0).toUpperCase() + role.slice(1) + ':';
        turnDiv.appendChild(strong);

        const textDiv = document.createElement('div');
        // Basic XSS prevention, and handle newlines
        const lines = text.split('\n');
        lines.forEach((line, index) => {
            textDiv.appendChild(document.createTextNode(line));
            if (index < lines.length - 1) {
                textDiv.appendChild(document.createElement('br'));
            }
        });
        turnDiv.appendChild(textDiv);
        
        conversationDisplay.appendChild(turnDiv);
        conversationDisplay.scrollTop = conversationDisplay.scrollHeight;
    }
});
</script>
</body>
</html>
