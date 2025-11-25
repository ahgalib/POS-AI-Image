<?php

// Check if the form was submitted and a file was uploaded
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['product_data_file'])) {
    $apiKey = $_POST['api_key'] ?? '';
    $fileType = $_POST['file_type'] ?? 'image';
    $uploadedFile = $_FILES['product_data_file'];
    $extractedData = null;
    $errorMessage = null;
    $jsonText = null;

    // --- 1. Basic Validation and File Handling ---
    if (empty($apiKey)) {
        $errorMessage = "API Key is required.";
    } elseif ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        $errorMessage = "File upload failed with error code: " . $uploadedFile['error'];
    } else {
        $filePath = $uploadedFile['tmp_name'];
        $mimeType = mime_content_type($filePath);

        // Define acceptable MIME types for robust checking
        $acceptableMimeTypes = [
            'image' => ['image/jpeg', 'image/png', 'image/webp'],
            'csv' => ['text/csv', 'text/plain', 'application/vnd.ms-excel'],
            'pdf' => ['application/pdf'],
        ];

        // Check if the actual MIME type matches the selected file type
        $isValidMime = false;
        foreach ($acceptableMimeTypes[$fileType] as $m) {
            if (str_contains($mimeType, $m) || $mimeType === $m) {
                $isValidMime = true;
                break;
            }
        }

        if (!$isValidMime) {
            $errorMessage = "Unsupported file type or mismatch. Selected: " . strtoupper($fileType) . ", Actual Mime: " . $mimeType . ".";
        } else {
            // --- 2. Construct the API Payload ---

            // Updated System Instruction for detailed extraction and error handling
            $systemInstruction = "You are an expert POS system data entry assistant.
1. **Analyze the input (Image, PDF, or CSV text) for a product list, receipt, or invoice.**
2. **If the input IS NOT a product list/receipt/invoice (e.g., a photo of a dog, a car, or blurry text), you MUST return a JSON object with a single property: {\"error_message\": \"Input does not contain a discernible product list or relevant document.\"}**
3. **If the input IS a product list/receipt/invoice, extract all required and available optional data.**
4. **'sell_price' is the unit price the customer pays.**
5. **'purchase_price' is the cost to the store (supplier/wholesale price). If only one price is visible, use it for 'sell_price' and set 'purchase_price' to null.**
6. **Return the result STRICTLY as a JSON object conforming to the provided schema. Do not add any conversational text.**";

            // Updated Response Schema for all requested fields (robust for structured output)
            $responseSchema = [
                "type" => "OBJECT",
                "description" => "A summary of the document, containing optional invoice details and a list of extracted products.",
                "properties" => [
                    "invoice_data" => [
                        "type" => "OBJECT",
                        "description" => "Optional document-level data, only include if found.",
                        "properties" => [
                            "invoice_no" => ["type" => "STRING", "description" => "The document or invoice number."]
                        ]
                    ],
                    "products" => [
                        "type" => "ARRAY",
                        "description" => "An array of product objects extracted from the list.",
                        "items" => [
                            "type" => "OBJECT",
                            "properties" => [
                                "product_id" => ["type" => "STRING", "description" => "The SKU, barcode, or unique product ID. Can be null."],
                                "product_name" => ["type" => "STRING", "description" => "The full name of the product."],
                                "sell_price" => ["type" => "NUMBER", "description" => "The selling price (customer unit price). Required."],
                                "purchase_price" => ["type" => "NUMBER", "description" => "The purchase (cost/wholesale) price. Can be null."],
                                "available_quantity" => ["type" => "NUMBER", "description" => "The numerical stock quantity. Can be null."]
                            ],
                            "required" => ["product_name", "sell_price"]
                        ]
                    ]
                ]
            ];

            $promptText = "Analyze this product list and extract the data.";
            $parts = [];

            if ($fileType === 'csv') {
                // For CSV, send the file content as pure text in the prompt
                $csvText = file_get_contents($filePath);
                $promptText = "The user has provided product data in the following CSV/text format. Analyze the table structure and extract the product list:\n\n" . $csvText;
                $parts = [
                    ['text' => $promptText]
                ];
            } else {
                // For Image and PDF, send as inlineData
                $fileData = file_get_contents($filePath);
                $base64Data = base64_encode($fileData);

                $parts = [
                    ['text' => $promptText],
                    [
                        'inlineData' => [
                            'mimeType' => $mimeType,
                            'data' => $base64Data
                        ]
                    ]
                ];
            }

            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => $parts
                    ]
                ],
                'systemInstruction' => [
                    'parts' => [['text' => $systemInstruction]]
                ],
                'generationConfig' => [
                    'responseMimeType' => "application/json",
                    'responseSchema' => $responseSchema
                ]
            ];

            $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-09-2025:generateContent?key=" . urlencode($apiKey);

            // --- 3. Send the API Request using cURL ---
            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);

            $apiResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                $errorMessage = "cURL Error: " . curl_error($ch);
            } elseif ($httpCode !== 200) {
                $errorMessage = "API Request Failed (HTTP " . $httpCode . "): " . $apiResponse;
            } else {
                $result = json_decode($apiResponse, true);

                // --- 4. Process the JSON Response ---
                $jsonText = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;

                if ($jsonText) {
                    // Decode the inner JSON string returned by the model
                    $extractedData = json_decode($jsonText, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $errorMessage = "Failed to parse JSON output from the model. Raw text: " . htmlspecialchars($jsonText);
                    } elseif (isset($extractedData['error_message'])) {
                        // Custom Error: Non-product Image/Document Detected
                        $errorMessage = $extractedData['error_message'];
                        $extractedData = null; // Clear data
                    } elseif (!isset($extractedData['products']) || !is_array($extractedData['products'])) {
                        // Fallback for an unexpected valid JSON structure missing the main product array
                        $errorMessage = "API returned a valid JSON object but the 'products' list is missing or invalid.";
                        $extractedData = null;
                    }
                    // If everything looks good, $extractedData is ready to be displayed
                } else {
                    $errorMessage = "API response missing content or candidate data.";
                }
            }
            curl_close($ch);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Product List Extractor (Gemini API)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f7f9fb;
        }

        .card {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
        }

        .error-message {
            background-color: #fee2e2;
            border-left: 4px solid #ef4444;
            color: #b91c1c;
        }

        .success-message {
            background-color: #d1fae5;
            border-left: 4px solid #10b981;
            color: #065f46;
        }
    </style>
</head>

<body class="p-4 md:p-8">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-3xl font-bold text-gray-800 mb-6 text-center">
            POS Product List Magician <span class="text-indigo-600">(Gemini API)</span>
        </h1>

        <?php if (isset($errorMessage)): ?>
            <div class="card p-4 mb-6 rounded-lg error-message">
                <p class="font-semibold">Extraction Error:</p>
                <p><?php echo htmlspecialchars($errorMessage); ?></p>
            </div>
        <?php endif; ?>

        <?php if (isset($extractedData) && $extractedData !== null): ?>
            <div class="card p-4 mb-6 rounded-lg success-message">
                <?php $productCount = isset($extractedData['products']) ? count($extractedData['products']) : 0; ?>
                <p class="font-bold">✨ Success! Extracted <?php echo $productCount; ?> products.</p>
                <p class="text-sm mt-1">This data is now ready to be inserted into your POS database.</p>
            </div>
        <?php endif; ?>

        <div class="card bg-white p-6 rounded-xl border border-gray-200">
            <h2 class="text-xl font-semibold mb-4 text-gray-700">Upload Product Data File</h2>
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-4">
                    <label for="api_key" class="block text-sm font-medium text-gray-700">Gemini API Key</label>
                    <input type="text" id="api_key" name="api_key"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500"
                        placeholder="Enter your API Key here" required
                        value="<?php echo $_POST['api_key'] ?? ''; ?>">
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Select File Type</label>
                    <div class="mt-1 flex space-x-4">
                        <label class="inline-flex items-center">
                            <input type="radio" name="file_type" value="image" checked
                                class="form-radio text-indigo-600" onchange="updateFileInput('image')">
                            <span class="ml-2 text-gray-700">Image (JPG/PNG/WEBP)</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="file_type" value="csv"
                                class="form-radio text-indigo-600" onchange="updateFileInput('csv')">
                            <span class="ml-2 text-gray-700">CSV/Text (.csv, .txt)</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="file_type" value="pdf"
                                class="form-radio text-indigo-600" onchange="updateFileInput('pdf')">
                            <span class="ml-2 text-gray-700">PDF Document (.pdf)</span>
                        </label>
                    </div>
                </div>

                <div class="mb-6">
                    <label for="product_data_file" class="block text-sm font-medium text-gray-700" id="file_label">Product List Image (Handwritten or Printed)</label>
                    <input type="file" id="product_data_file" name="product_data_file" accept="image/*" required
                        class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                </div>

                <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 ease-in-out">
                    Extract Data Magically
                </button>
            </form>
        </div>

        <?php if (isset($extractedData['products']) && is_array($extractedData['products']) && count($extractedData['products']) > 0): ?>
            <div class="mt-8 card bg-white p-6 rounded-xl border border-gray-200">
                <h2 class="text-xl font-semibold mb-4 text-gray-700">Extracted Product Data</h2>
                <?php if (isset($extractedData['invoice_data']['invoice_no'])): ?>
                    <p class="mb-4 text-sm text-gray-600">**Invoice/Document No:** **<?php echo htmlspecialchars($extractedData['invoice_data']['invoice_no']); ?>**</p>
                <?php endif; ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sell Price</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Purchase Price</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product ID</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($extractedData['products'] as $product): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($product['product_name'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        $<?php echo number_format($product['sell_price'] ?? 0, 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        $<?php echo number_format($product['purchase_price'] ?? 0, 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($product['available_quantity'] ?? '0'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($product['product_id'] ?? 'N/A'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($jsonText)): ?>
            <div class="mt-8 card bg-gray-800 p-4 rounded-xl">
                <h3 class="text-lg font-semibold text-white mb-2">Raw JSON Output (For Debugging)</h3>
                <pre class="bg-gray-700 p-3 rounded-lg text-xs text-green-300 overflow-x-auto whitespace-pre-wrap"><?php echo htmlspecialchars($jsonText); ?></pre>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function updateFileInput(fileType) {
            const fileInput = document.getElementById('product_data_file');
            const fileLabel = document.getElementById('file_label');

            if (fileType === 'image') {
                fileInput.accept = 'image/jpeg, image/png, image/webp';
                fileLabel.textContent = 'Product List Image (JPG, PNG, WEBP)';
            } else if (fileType === 'csv') {
                fileInput.accept = 'text/csv, application/vnd.ms-excel, text/plain';
                fileLabel.textContent = 'Product Data File (CSV or Text)';
            } else if (fileType === 'pdf') {
                fileInput.accept = 'application/pdf';
                fileLabel.textContent = 'Product List Document (PDF)';
            }
        }

        // Initialize file input on load
        document.addEventListener('DOMContentLoaded', () => {
            updateFileInput('image');
        });
    </script>
</body>

</html>