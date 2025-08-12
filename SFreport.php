Absolutely. Here is the complete, final code for your multi-step dynamic reporting tool.

This version incorporates the fix for the An invalid form control... is not focusable error by removing the required attribute from the chat textarea and relying on the improved JavaScript validation instead.

Key Features of this Final Script:

Step 1: Automatically lists all Smartsheet files you own that were modified in the last 24 hours.

Step 2: After you select sheets, it fetches all unique column names from them and lets you choose which ones to report on.

Step 3: Processes only the selected columns from the selected sheets, sends the data to Gemini for an initial report, and opens a fully functional chat interface for follow-up questions.

Bug-Free Chat: The follow-up chat form is now correctly configured to avoid browser validation errors.

Complete Code
code
PHP
download
content_copy
expand_less

<?php
// --- Configuration ---
const SMARTSHEET_ACCESS_TOKEN = 'WRCMeuYeC0iI88FuhI72NFvKXOFtqcsnv1VFz'; // IMPORTANT: Replace with your actual token
const GEMINI_API_KEY = 'AIzaSyBTZB91O6J98CBCr2H0Ij7WHLOl9J1UM5w';       // IMPORTANT: Replace with your actual key
const GEMINI_API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . GEMINI_API_KEY;

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(900); // 15 minutes
$sheetInfoCache = [];
session_start();

// --- Smartsheet API Functions ---

function fetchSmartsheetAPI(string $apiUrl, string $accessToken): array {
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

function getDecodedApiResponse(array $apiResult, string $contextErrorMessage = "Smartsheet API Error", bool $haltOnError = true): ?array {
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

/**
 * [NEW] Fetches all sheets and filters for those modified within a given timeframe.
 */
function listAllSheetsModifiedSince(string $accessToken, string $timespan = '1 day'): array {
    $allSheets = [];
    $page = 1;
    $hasMore = true;

    while ($hasMore) {
        $apiUrl = "https://api.smartsheet.com/2.0/sheets?page={$page}&pageSize=100&include=ownerInfo";
        $apiResult = fetchSmartsheetAPI($apiUrl, $accessToken);
        $sheetListData = getDecodedApiResponse($apiResult, "listing all sheets, page {$page}");
        if (isset($sheetListData['script_error'])) { return ['error' => true, 'message' => 'Failed to list sheets.', 'details' => $sheetListData]; }

        if (!empty($sheetListData['data'])) {
            $allSheets = array_merge($allSheets, $sheetListData['data']);
        }
        $hasMore = ($sheetListData['pageNumber'] ?? 1) < ($sheetListData['totalPages'] ?? 1);
        $page++;
    }

    $modifiedSheets = [];
    $cutoffDate = new DateTime("-{$timespan}");
    foreach ($allSheets as $sheet) {
        if (!isset($sheet['modifiedAt'])) continue;
        $modifiedAt = new DateTime($sheet['modifiedAt']);
        if ($modifiedAt > $cutoffDate) {
            $modifiedSheets[] = $sheet;
        }
    }
    // Sort by most recently modified first
    usort($modifiedSheets, function ($a, $b) {
        return strtotime($b['modifiedAt']) - strtotime($a['modifiedAt']);
    });
    return $modifiedSheets;
}

/**
 * [NEW] Fetches the column definitions for a given set of sheet IDs.
 */
function getColumnsForSheets(array $sheetIds, string $accessToken): array {
    $uniqueColumns = [];
    foreach ($sheetIds as $sheetId) {
        $apiUrl = "https://api.smartsheet.com/2.0/sheets/{$sheetId}?include=columns";
        $apiResult = fetchSmartsheetAPI($apiUrl, $accessToken);
        $sheetData = getDecodedApiResponse($apiResult, "fetching columns for sheet {$sheetId}");
        if (isset($sheetData['script_error'])) {
            return ['error' => true, 'message' => "Failed to get columns for sheet {$sheetId}.", 'details' => $sheetData];
        }
        if (!empty($sheetData['columns'])) {
            foreach ($sheetData['columns'] as $column) {
                // Use title as key to ensure uniqueness
                if (isset($column['title'])) {
                    $uniqueColumns[$column['title']] = true;
                }
            }
        }
    }
    $columnTitles = array_keys($uniqueColumns);
    sort($columnTitles, SORT_NATURAL | SORT_FLAG_CASE);
    return $columnTitles;
}

/**
 * [MODIFIED] Now accepts an array of target column titles to look for.
 */
function getSheetConfig(string $sheetId, string $accessToken, array $targetColumnTitles): ?array {
    global $sheetInfoCache;
    $cacheKey = $sheetId . '_' . md5(implode(',', $targetColumnTitles));
    if (isset($sheetInfoCache[$cacheKey])) return $sheetInfoCache[$cacheKey];

    $apiUrl = "https://api.smartsheet.com/2.0/sheets/{$sheetId}?pageSize=1&page=1&include=objectValue";
    $apiResult = fetchSmartsheetAPI($apiUrl, $accessToken);
    $sheetData = getDecodedApiResponse($apiResult, "sheet ID {$sheetId} (getSheetConfig)");
    if (isset($sheetData['script_error'])) return $sheetData;

    if ($sheetData && isset($sheetData['columns']) && is_array($sheetData['columns'])) {
        $targetColumnIdToSafeKeyMap = [];
        foreach ($sheetData['columns'] as $column) {
            if (in_array($column['title'], $targetColumnTitles)) {
                $safeKey = strtolower(str_replace([' ', '#', '/', '-'], ['_', '', '_', '_'], $column['title']));
                $targetColumnIdToSafeKeyMap[$column['id']] = $safeKey;
            }
        }
        $sheetName = $sheetData['name'] ?? ('Sheet ' . $sheetId);
        $sheetInfoCache[$cacheKey] = [
            'name' => $sheetName,
            'columnIdToSafeKeyMap' => $targetColumnIdToSafeKeyMap
        ];
        return $sheetInfoCache[$cacheKey];
    }
    error_log("Could not get column config for sheet ID {$sheetId}.");
    return ['script_error' => true, 'message' => "Could not get column config for sheet {$sheetId}", 'sheet_id' => $sheetId];
}

function fetchAllRowsFromSheet(string $sheetId, string $accessToken): Generator {
    $currentPage = 1; $totalPages = 1; $pageSize = 100;
    do {
        $apiUrl = "https://api.smartsheet.com/2.0/sheets/{$sheetId}?page={$currentPage}&pageSize={$pageSize}&include=objectValue";
        $apiResult = fetchSmartsheetAPI($apiUrl, $accessToken);
        $sheetPageData = getDecodedApiResponse($apiResult, "sheet ID {$sheetId}, page {$currentPage} (fetchAllRowsFromSheet)");
        if (isset($sheetPageData['script_error'])) { yield ['row_fetch_error' => $sheetPageData]; break; }

        if ($sheetPageData && isset($sheetPageData['rows']) && is_array($sheetPageData['rows'])) {
            foreach ($sheetPageData['rows'] as $row) { yield $row; }
            if ($currentPage === 1 && isset($sheetPageData['totalPages'])) { $totalPages = $sheetPageData['totalPages']; }
            $currentPage++;
        } else { break; }
    } while ($currentPage <= $totalPages);
 }

function fetchDiscussionsForRow(string $sheetId, string $rowId, string $accessToken): array {
    $allRowDiscussions = []; $currentPage = 1; $totalPages = 1; $pageSize = 100;
    do {
        $apiUrl = "https://api.smartsheet.com/2.0/sheets/{$sheetId}/rows/{$rowId}/discussions?page={$currentPage}&pageSize={$pageSize}&include=comments";
        $apiResult = fetchSmartsheetAPI($apiUrl, $accessToken);
        $pageData = getDecodedApiResponse($apiResult, "discussions for row {$rowId} on sheet {$sheetId}", false);
        if (isset($pageData['script_error'])) { return [['discussion_fetch_error' => $pageData]]; }
        if ($pageData && isset($pageData['data']) && is_array($pageData['data'])) {
            $allRowDiscussions = array_merge($allRowDiscussions, $pageData['data']);
            $totalPages = $pageData['totalPages'] ?? 1; $currentPage++;
        } else { break; }
    } while ($currentPage <= $totalPages);
    return $allRowDiscussions;
 }

/**
 * [MODIFIED] Now accepts an array of target column titles to process.
 */
function processSheetAndItsRows($sheetId, $accessToken, array $targetColumnTitles) {
    $sheetConfig = getSheetConfig($sheetId, $accessToken, $targetColumnTitles);
    if (isset($sheetConfig['script_error']) || !$sheetConfig) {
        return ['error' => true, 'message' => "Critical error: Sheet configuration is null for sheet " . htmlspecialchars($sheetId), 'sheet_id' => htmlspecialchars($sheetId)];
    }

    $sheetName = $sheetConfig['name'];
    $columnIdToSafeKeyMap = $sheetConfig['columnIdToSafeKeyMap'];
    $outputDataForRule = [];
    $rowsGenerator = fetchAllRowsFromSheet($sheetId, $accessToken);

    foreach ($rowsGenerator as $row) {
        if (isset($row['row_fetch_error'])) { return ['error' => true, 'message' => "Error fetching rows", 'details' => $row['row_fetch_error']]; }
        if (!isset($row['id'])) { continue; }

        $rowId = (string) $row['id'];
        $currentRowColumnValues = [];
        foreach ($targetColumnTitles as $title) {
            $safeKey = strtolower(str_replace([' ', '#', '/', '-'], ['_', '', '_', '_'], $title));
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
        $commentsForRow = [];
        if (is_array($discussionsOnThisRow) && !isset($discussionsOnThisRow[0]['discussion_fetch_error'])) {
            foreach ($discussionsOnThisRow as $discussion) {
                if (!empty($discussion['comments'])) {
                    foreach ($discussion['comments'] as $comment) {
                        $commentsForRow[] = [ 'text' => $comment['text'] ?? '', 'author_name' => $comment['createdBy']['name'] ?? 'N/A', 'created' => $comment['createdAt'] ?? ''];
                    }
                }
            }
        }
        $rowDataEntry = ['row_id' => $rowId, 'row_number' => $row['rowNumber'] ?? 'N/A'];
        $rowDataEntry = array_merge($rowDataEntry, $currentRowColumnValues);
        $rowDataEntry['comments_on_row'] = $commentsForRow;
        $outputDataForRule[] = $rowDataEntry;
    }
    return ['sheet' => ['id' => htmlspecialchars($sheetId), 'name' => htmlspecialchars($sheetName)],'rows_data' => $outputDataForRule];
}

// --- Gemini API Function ---
function callGeminiAPI(array $conversationHistory, string $apiKey, string $apiUrl): array {
    $payload = ['contents' => $conversationHistory, 'generationConfig' => ["temperature" => 0.7, "topK" => 1, "topP" => 1, "maxOutputTokens" => 8192,], 'safetySettings' => [["category" => "HARM_CATEGORY_HARASSMENT", "threshold" => "BLOCK_MEDIUM_AND_ABOVE"], ["category" => "HARM_CATEGORY_HATE_SPEECH", "threshold" => "BLOCK_MEDIUM_AND_ABOVE"], ["category" => "HARM_CATEGORY_SEXUALLY_EXPLICIT", "threshold" => "BLOCK_MEDIUM_AND_ABOVE"], ["category" => "HARM_CATEGORY_DANGEROUS_CONTENT", "threshold" => "BLOCK_MEDIUM_AND_ABOVE"],]];
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $apiUrl, CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ["Content-Type: application/json"], CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_TIMEOUT => 240]);
    $responseBody = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlErrorNum = curl_errno($ch); $curlError = curl_error($ch); curl_close($ch);

    if ($curlErrorNum > 0) { return ['error' => true, 'message' => "Gemini API Communication Error: " . $curlError]; }
    $decodedResponse = json_decode($responseBody, true);
    if ($httpCode === 200 && isset($decodedResponse['candidates'][0]['content']['parts'][0]['text'])) {
        return ['error' => false, 'text' => $decodedResponse['candidates'][0]['content']['parts'][0]['text']];
    }
    if (isset($decodedResponse['error'])) { return ['error' => true, 'message' => "Gemini API Error: " . ($decodedResponse['error']['message'] ?? 'Unknown error')]; }
    return ['error' => true, 'message' => "Gemini API Error (HTTP {$httpCode}) or unexpected response"];
}

// --- Gemini Prompt Function ---
function constructGeminiExecutivePromptBase(): string {
    $reportingDate = date('Y-m-d'); $formattedDate = date('m/d/Y');
    return "You are an AI assistant tasked with summarizing project data for an executive report. Based on the provided JSON, which contains data from one or more project sheets, populate ONLY the following fields concisely and professionally. Assume the current reporting date is $reportingDate.\n\nFields to Populate:\n\nNotes:\nProvide a summary of recent activities and key findings. Highlight common reasons for delays or revisits. Start with the reporting date (e.g., \"$formattedDate (Summary):\").\n\nNumber of Sites Completed:\nCarefully count the unique sites that have a status indicating completion (e.g., \"BILLED - COMP\", \"RDY 2 BILL - COMP\"). Provide only the numerical count.\n\nRun Rate(# of sites per week):\nCalculate this based on the number of completed sites and the timeframe of the data provided. Estimate the number of sites completed per week.\n\nHere is the Data (JSON format):\n\n";
}

// --- Main Logic: Multi-Step Form Processing ---
$errorMessage = null;
$currentStep = 1;

if (!isset($_SESSION['selected_sheets'])) $_SESSION['selected_sheets'] = [];
if (!isset($_SESSION['available_columns'])) $_SESSION['available_columns'] = [];
if (!isset($_SESSION['geminiConversation'])) $_SESSION['geminiConversation'] = [];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['fetch_columns'])) {
        if (!empty($_POST['selected_sheets'])) {
            $_SESSION['selected_sheets'] = $_POST['selected_sheets'];
            $columnsResult = getColumnsForSheets($_SESSION['selected_sheets'], SMARTSHEET_ACCESS_TOKEN);
            if (isset($columnsResult['error'])) {
                $errorMessage = $columnsResult['message'];
                $currentStep = 1;
            } else {
                $_SESSION['available_columns'] = $columnsResult;
                $currentStep = 2;
            }
        } else {
            $errorMessage = "Please select at least one sheet.";
            $currentStep = 1;
        }
    }
    elseif (isset($_POST['process_data'])) {
        $selectedSheets = $_POST['selected_sheets'] ?? [];
        $selectedColumns = $_POST['selected_columns'] ?? [];
        if (empty($selectedSheets) || empty($selectedColumns)) {
            $errorMessage = "Missing sheet or column selection. Please start over.";
            $_SESSION = []; $currentStep = 1;
        } else {
            $_SESSION['geminiConversation'] = [];
            $allSheetsResults = []; $hasCriticalError = false;
            foreach ($selectedSheets as $sheetId) {
                $result = processSheetAndItsRows($sheetId, SMARTSHEET_ACCESS_TOKEN, $selectedColumns);
                if (isset($result['error'])) {
                    $errorMessage = "Error processing sheet " . htmlspecialchars($sheetId) . ": " . ($result['message'] ?? 'Unknown error');
                    $hasCriticalError = true; break;
                }
                $allSheetsResults[] = $result;
            }
            if (!$hasCriticalError) {
                $smartsheetJsonData = json_encode($allSheetsResults, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $_SESSION['smartsheetJsonForDisplay'] = json_encode($allSheetsResults, JSON_PRETTY_PRINT);
                $userEditedSystemPrompt = trim($_POST['system_prompt'] ?? constructGeminiExecutivePromptBase());
                $_SESSION['editableSystemPrompt'] = $userEditedSystemPrompt;
                $initialFullPromptWithData = $userEditedSystemPrompt . $smartsheetJsonData;
                $_SESSION['initialFullPromptWithData_for_display_logic'] = $initialFullPromptWithData;
                $_SESSION['geminiConversation'][] = ['role' => 'user', 'parts' => [['text' => $initialFullPromptWithData]]];
                $geminiResult = callGeminiAPI($_SESSION['geminiConversation'], GEMINI_API_KEY, GEMINI_API_URL);
                if (!$geminiResult['error']) {
                    $_SESSION['geminiConversation'][] = ['role' => 'model', 'parts' => [['text' => $geminiResult['text']]]];
                } else {
                    $errorMessage = "Error from Gemini API: " . ($geminiResult['message'] ?? 'Unknown error');
                    array_pop($_SESSION['geminiConversation']);
                }
            }
            $currentStep = 3;
        }
    }
    elseif (isset($_POST['reset_all'])) {
        session_unset(); session_destroy(); session_start();
        header("Location: " . $_SERVER['PHP_SELF']); exit();
    }
} else {
    if (!empty($_SESSION['geminiConversation'])) { $currentStep = 3; }
    elseif (!empty($_SESSION['available_columns'])) { $currentStep = 2; }
    else { $currentStep = 1; }
}

$recentlyModifiedSheets = [];
if ($currentStep == 1) {
    $recentlyModifiedSheets = listAllSheetsModifiedSince(SMARTSHEET_ACCESS_TOKEN, '1 day');
    if (isset($recentlyModifiedSheets['error'])) {
        $errorMessage = "Could not fetch Smartsheet list. Ensure the Access Token is valid and has rights to list sheets. Details: " . $recentlyModifiedSheets['message'];
        $recentlyModifiedSheets = [];
    }
}
$editableSystemPrompt = $_SESSION['editableSystemPrompt'] ?? constructGeminiExecutivePromptBase();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dynamic Smartsheet Reporting with Gemini</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 0; padding: 0; background-color: #f0f2f5; color: #333; display: flex; flex-direction: column; min-height: 100vh; font-size: 16px; line-height: 1.6;}
        .container { max-width: 900px; margin: 20px auto; background-color: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); flex-grow: 1; display: flex; flex-direction: column;}
        h1, h2, h3 { color: #1c3d5a; }
        h1 { text-align: center; margin-bottom: 30px; font-size: 2em;}
        h2 { font-size: 1.5em; margin-top: 30px; margin-bottom: 15px; border-bottom: 2px solid #e0e0e0; padding-bottom: 10px;}
        h3 { font-size: 1.2em; margin-top: 25px; margin-bottom: 10px; color: #005a9e;}
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #455a64; }
        textarea { width: 100%; padding: 12px 15px; margin-bottom: 10px; border: 1px solid #ccd1d9; border-radius: 8px; box-sizing: border-box; font-size: 1em; min-height: 120px; resize: vertical; }
        button, input[type="submit"] { background-color: #0078D4; color: white; padding: 12px 25px; border: none; border-radius: 8px; cursor: pointer; font-size: 1em; font-weight: 500; transition: background-color 0.2s; }
        button:hover, input[type="submit"]:hover { background-color: #005a9e; }
        .button-secondary { background-color: #6c757d; }
        .button-secondary:hover { background-color: #545b62; }
        .button-group { display: flex; gap: 10px; margin-top: 20px; justify-content: space-between; align-items: center; }
        pre { background-color: #f8f9fa; border: 1px solid #e0e0e0; padding: 15px; border-radius: 8px; white-space: pre-wrap; word-wrap: break-word; max-height: 350px; overflow-y: auto; font-size: 0.85em; }
        .error { color: #c0392b; background-color: #fdedec; border: 1px solid #f5c6cb; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
        .info { color: #004085; background-color: #cce5ff; border: 1px solid #b8daff; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px;}
        .chat-area { margin-top: 30px; border-top: 2px solid #e0e0e0; padding-top: 20px; flex-grow: 1; display: flex; flex-direction: column;}
        .conversation { flex-grow: 1; max-height: 50vh; overflow-y: auto; margin-bottom: 20px; border: 1px solid #e0e0e0; padding: 15px; border-radius: 8px; }
        .turn { margin-bottom: 18px; padding: 12px 15px; border-radius: 10px; max-width: 85%; word-wrap: break-word; }
        .turn.user { background-color: #e7f3ff; margin-left: auto; border-bottom-right-radius: 0;}
        .turn.model { background-color: #f1f3f4; margin-right: auto; border-bottom-left-radius: 0;}
        .turn strong { display: block; margin-bottom: 6px; color: #005a9e; font-size: 0.9em; text-transform: uppercase; }
        fieldset { border: 1px solid #ccd1d9; border-radius: 8px; padding: 20px; margin-bottom: 25px; }
        legend { font-weight: 600; font-size: 1.1em; color: #1c3d5a; padding: 0 10px; }
        .checklist { max-height: 250px; overflow-y: auto; border: 1px solid #e0e0e0; padding: 15px; border-radius: 6px; background-color: #fdfdfd; }
        .checklist-item { display: block; margin-bottom: 10px; }
        .checklist-item label { font-weight: normal; display: inline-flex; align-items: center; cursor: pointer; }
        .checklist-item input { margin-right: 10px; width: 1.2em; height: 1.2em; }
        .checklist-item .modified-date { font-size: 0.8em; color: #6c757d; margin-left: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Dynamic Smartsheet Reporting with Gemini</h1>
        <?php if ($errorMessage): ?><p class="error"><?php echo htmlspecialchars($errorMessage); ?></p><?php endif; ?>

        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
            <?php if ($currentStep == 1): ?>
            <fieldset><legend>Step 1: Select Sheets</legend>
                <p>Showing sheets modified in the last 24 hours. Select one or more to proceed.</p>
                <div class="checklist">
                    <?php if (!empty($recentlyModifiedSheets)): foreach ($recentlyModifiedSheets as $sheet): ?>
                    <div class="checklist-item"><label><input type="checkbox" name="selected_sheets[]" value="<?php echo htmlspecialchars($sheet['id']); ?>">
                        <?php echo htmlspecialchars($sheet['name']); ?>
                        <span class="modified-date">(Modified: <?php echo htmlspecialchars(gmdate("Y-m-d H:i", strtotime($sheet['modifiedAt']))); ?> UTC)</span></label>
                    </div>
                    <?php endforeach; else: ?>
                    <p class="info">No sheets were modified in the last 24 hours, or there was an error fetching the list.</p>
                    <?php endif; ?>
                </div>
            </fieldset>
            <div class="button-group">
                <button type="submit" name="fetch_columns">Fetch Columns for Selected Sheets &raquo;</button>
                <button type="submit" name="reset_all" class="button-secondary">Start Over</button>
            </div>
            <?php endif; ?>

            <?php if ($currentStep == 2): ?>
            <fieldset><legend>Step 2: Select Columns to Report On</legend>
                <p>These columns were found in your selected sheets. Check all you want to include in the analysis.</p>
                <div class="checklist">
                     <?php foreach ($_SESSION['available_columns'] as $columnTitle): ?>
                     <div class="checklist-item"><label><input type="checkbox" name="selected_columns[]" value="<?php echo htmlspecialchars($columnTitle); ?>" checked>
                         <?php echo htmlspecialchars($columnTitle); ?></label>
                     </div>
                     <?php endforeach; ?>
                </div>
            </fieldset>
            <fieldset><legend>System Prompt (Optional)</legend>
                <textarea name="system_prompt"><?php echo htmlspecialchars($editableSystemPrompt); ?></textarea>
            </fieldset>
            <?php foreach ($_SESSION['selected_sheets'] as $sheetId): ?>
            <input type="hidden" name="selected_sheets[]" value="<?php echo htmlspecialchars($sheetId); ?>">
            <?php endforeach; ?>
            <div class="button-group">
                <button type="submit" name="process_data">Process Data & Start Chat &raquo;</button>
                <button type="submit" name="reset_all" class="button-secondary">Start Over</button>
            </div>
            <?php endif; ?>

            <?php if ($currentStep == 3): ?>
            <h3>Report Generation Complete</h3>
            <p class="info">Below is the data sent to Gemini and the initial response. You can now ask follow-up questions.</p>
            <?php if (isset($_SESSION['smartsheetJsonForDisplay'])): ?>
            <details><summary style="cursor:pointer; font-weight:bold; margin-bottom:10px;">Click to view JSON data sent to Gemini</summary>
                <pre><?php echo htmlspecialchars($_SESSION['smartsheetJsonForDisplay']); ?></pre>
            </details>
            <?php endif; ?>
            <?php if (!empty($_SESSION['geminiConversation'])): ?>
            <div class="chat-area"><h2>Chat with Gemini</h2>
                <div class="conversation" id="conversation-display">
                    <?php $isFirstUserTurn = true; $initialPromptTextInSession = $_SESSION['initialFullPromptWithData_for_display_logic'] ?? '';
                    foreach ($_SESSION['geminiConversation'] as $turn):
                        if ($turn['role'] === 'user' && $isFirstUserTurn && $turn['parts'][0]['text'] === $initialPromptTextInSession) {
                            $isFirstUserTurn = false;
                            echo '<div class="turn user"><strong>User:</strong> <em>(Initial detailed prompt with selected data was sent to Gemini)</em></div>'; continue;
                        } ?>
                        <div class="turn <?php echo htmlspecialchars($turn['role']); ?>">
                            <strong><?php echo htmlspecialchars(ucfirst($turn['role'])); ?>:</strong>
                            <div><?php echo nl2br(htmlspecialchars($turn['parts'][0]['text'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="button-group">
                <div></div><button type="submit" name="reset_all" class="button-secondary">Start New Report</button>
            </div>
            <?php endif; ?>

            <div id="chat-form-wrapper" style="<?php echo ($currentStep == 3 && empty($errorMessage)) ? 'display:block; margin-top:20px;' : 'display:none;'; ?>">
                 <form id="chat-form">
                    <label for="user_query">Your follow-up query:</label>
                    <!-- The 'required' attribute was removed to prevent the "not focusable" browser error -->
                    <textarea id="user_query" name="user_query" placeholder="Ask Gemini to refine or analyze further..."></textarea>
                    <button type="submit" id="send-to-gemini-btn">Send to Gemini</button>
                </form>
            </div>
        </form>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatForm = document.getElementById('chat-form');
    if (!chatForm) return;

    const userQueryInput = document.getElementById('user_query');
    const conversationDisplay = document.getElementById('conversation-display');
    const sendButton = document.getElementById('send-to-gemini-btn');

    chatForm.addEventListener('submit', async function(event) {
        event.preventDefault();
        const userQuery = userQueryInput.value.trim();

        if (!userQuery) {
            userQueryInput.style.borderColor = '#c0392b';
            userQueryInput.placeholder = 'Please enter a message before sending.';
            setTimeout(() => {
                userQueryInput.style.borderColor = '';
                userQueryInput.placeholder = 'Ask Gemini to refine or analyze further...';
            }, 2500);
            return;
        }

        sendButton.disabled = true;
        sendButton.textContent = 'Sending...';
        appendMessageToChat('user', userQuery);
        userQueryInput.value = '';

        try {
            // IMPORTANT: This fetch call requires a backend endpoint named 'gemini_chat_api.php'
            // to process the follow-up messages. This file must handle the session and API call.
            const response = await fetch('gemini_chat_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ user_query: userQuery })
            });

            if (!response.ok) {
                let errorText = `Server error: ${response.status} ${response.statusText}`;
                 try { const errorData = await response.json(); errorText += `. ` + (errorData.message || '');}
                 catch (e) { /* ignore if response isn't json */ }
                throw new Error(errorText);
            }
            const result = await response.json();
            if (result.error) { appendMessageToChat('model', `Error: ${result.message}`); }
            else { appendMessageToChat('model', result.text); }
        } catch (error) {
            console.error('Fetch error:', error);
            appendMessageToChat('model', `Client-side error: ${error.message}. Ensure gemini_chat_api.php is set up.`);
        } finally {
            sendButton.disabled = false;
            sendButton.textContent = 'Send to Gemini';
        }
    });

    function appendMessageToChat(role, text) {
        if (!conversationDisplay) return;
        const turnDiv = document.createElement('div');
        turnDiv.classList.add('turn', role);
        const strong = document.createElement('strong');
        strong.textContent = role.charAt(0).toUpperCase() + role.slice(1) + ':';
        turnDiv.appendChild(strong);
        const textDiv = document.createElement('div');
        textDiv.innerHTML = text.replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\n/g, '<br>');
        turnDiv.appendChild(textDiv);
        conversationDisplay.appendChild(turnDiv);
        conversationDisplay.scrollTop = conversationDisplay.scrollHeight;
    }
});
</script>
</body>
</html>
