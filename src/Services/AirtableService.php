<?php
namespace WFOT\Services;

use TANIOS\Airtable\Airtable;
use Exception; // Import Exception class for error handling

class AirtableService
{
    private Airtable $sdk;
    private bool $isDebug; // Add debug flag property

    public function __construct()
    {
        $this->sdk = new Airtable([
            'api_key' => env('AIRTABLE_API_KEY'),
            'base'    => env('AIRTABLE_BASE_ID'),
        ]);
        // Initialize debug flag
        $this->isDebug = env('DEBUG') === true || strtolower(env('DEBUG')) === 'true';
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /** Normalise SDK record → associative array */
    private function recordToArray(object $rec): array
    {
        return [
            'id'     => $rec->id,
            'fields' => (array) $rec->fields,
        ];
    }

    /** Log debug messages */
    private function logDebug(string $message): void
    {
        if ($this->isDebug) {
            error_log("AirtableService DEBUG: " . $message);
        }
    }

    /** One record */
    public function find(string $table, string $id): ?array
    {
        $this->logDebug("find() called - Table: $table, ID: $id");
        try {
            $request = $this->sdk->getContent("$table/$id");
            $response = $request->getResponse(); // Get the raw response object

            if ($response && !isset($response->error)) {
                $record = $this->recordToArray($response);
                $this->logDebug("find() success - Record found: " . json_encode($record));
                return $record;
            } else {
                $errorMsg = isset($response->error) ? json_encode($response->error) : 'No record found or empty response';
                $this->logDebug("find() failed or not found - Error/Details: $errorMsg");
                return null;
            }
        } catch (Exception $e) {
            $this->logDebug("find() exception - Table: $table, ID: $id, Error: " . $e->getMessage());
            error_log("AirtableService ERROR: find() failed for $table/$id - " . $e->getMessage());
            return null; // Return null on exception
        }
    }

    /** Many records (handles pagination) */
    public function all(string $table, array $params = []): array
    {
        $this->logDebug("all() called - Table: $table, Params: " . json_encode($params));
        $out = [];
        try {
            $request = $this->sdk->getContent($table, $params);
            $pageCount = 0;

            do {
                $pageCount++;
                $this->logDebug("all() - Fetching page $pageCount for table $table");
                $response = $request->getResponse(); // Response object

                if (isset($response->error)) {
                    $this->logDebug("all() error on page $pageCount - Error: " . json_encode($response->error));
                    // Decide whether to stop or continue (e.g., break;)
                    // For now, we log and stop processing further pages on error.
                    error_log("AirtableService ERROR: all() failed on page $pageCount for table $table - " . json_encode($response->error));
                    break;
                }

                $recordsOnPage = $response['records'] ?? [];
                $this->logDebug("all() - Page $pageCount received " . count($recordsOnPage) . " records.");

                foreach ($recordsOnPage as $rec) {
                    $out[] = $this->recordToArray($rec); // cast to array shape
                }

                // Check for next page link
                $request = $response->next(); // Get the request object for the next page, or null

            } while ($request); // Continue if $request is not null

            $this->logDebug("all() completed - Table: $table, Total records fetched: " . count($out));
            return $out;

        } catch (Exception $e) {
            $this->logDebug("all() exception - Table: $table, Params: " . json_encode($params) . ", Error: " . $e->getMessage());
            error_log("AirtableService ERROR: all() failed for table $table - " . $e->getMessage());
            return []; // Return empty array on exception
        }
    }


    /** Create */
    public function create(string $table, array $fields): array
    {
        $this->logDebug("create() called - Table: $table, Fields: " . json_encode($fields));
        try {
            // Correct: Pass the fields array directly as per documentation
            $response = $this->sdk->saveContent($table, $fields);

            if ($response && !isset($response->error)) {
                 $record = $this->recordToArray($response);
                 $this->logDebug("create() success - Record created: " . json_encode($record));
                 return $record;
            } else {
                 $errorMsg = isset($response->error) ? json_encode($response->error) : 'Create operation failed or returned empty response';
                 $this->logDebug("create() failed - Error/Details: $errorMsg");
                 // Throw an exception or return an empty array/null based on desired error handling
                 throw new Exception("Airtable create failed: " . $errorMsg);
            }
        } catch (Exception $e) {
            $this->logDebug("create() exception - Table: $table, Fields: " . json_encode($fields) . ", Error: " . $e->getMessage());
            error_log("AirtableService ERROR: create() failed for table $table - " . $e->getMessage());
            // Re-throw the exception to be caught by the calling code
            throw $e;
        }
    }


    /** Update */
    public function update(string $table, string $id, array $fields): array
    {
       $this->logDebug("update() called - Table: $table, ID: $id, Fields: " . json_encode($fields));
        try {
             // Change: Pass $fields directly, assuming updateContent works like saveContent
            $response = $this->sdk->updateContent("$table/$id", $fields);

            if ($response && !isset($response->error)) {
                 $record = $this->recordToArray($response);
                 $this->logDebug("update() success - Record updated: " . json_encode($record));
                 return $record;
            } else {
                 $errorMsg = isset($response->error) ? json_encode($response->error) : 'Update operation failed or returned empty response';
                 $this->logDebug("update() failed - Error/Details: $errorMsg");
                 throw new Exception("Airtable update failed: " . $errorMsg);
            }
        } catch (Exception $e) {
            $this->logDebug("update() exception - Table: $table, ID: $id, Fields: " . json_encode($fields) . ", Error: " . $e->getMessage());
            error_log("AirtableService ERROR: update() failed for $table/$id - " . $e->getMessage());
            throw $e; // Re-throw
        }
    }

    /** Upload attachment to a record */
    public function uploadAttachment(string $table, string $recordId, string $fieldId, string $filename, string $pdfContent): bool
    {
        $this->logDebug("uploadAttachment() called - Table: $table, Record: $recordId, Field: $fieldId, Filename: $filename");
        
        try {
            $apiKey = env('AIRTABLE_API_KEY');
            $baseId = env('AIRTABLE_BASE_ID');
            
            $url = "https://api.airtable.com/v0/{$baseId}/{$table}";
            
            // Create attachment data
            $attachmentData = [
                'records' => [
                    [
                        'id' => $recordId,
                        'fields' => [
                            $fieldId => [
                                [
                                    'url' => 'data:application/pdf;base64,' . base64_encode($pdfContent),
                                    'filename' => $filename
                                ]
                            ]
                        ]
                    ]
                ]
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$apiKey}",
                "Content-Type: application/json"
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($attachmentData));
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $this->logDebug("uploadAttachment() success - File uploaded successfully");
                return true;
            } else {
                $this->logDebug("uploadAttachment() failed - HTTP {$httpCode}, Response: {$response}");
                error_log("AirtableService ERROR: uploadAttachment() failed - HTTP {$httpCode}, Response: {$response}");
                return false;
            }
            
        } catch (Exception $e) {
            $this->logDebug("uploadAttachment() exception - Error: " . $e->getMessage());
            error_log("AirtableService ERROR: uploadAttachment() exception - " . $e->getMessage());
            return false;
        }
    }
}