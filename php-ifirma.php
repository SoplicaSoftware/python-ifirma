<?php

/**
 * PHP counterpart of python-ifirma
 * A simple wrapper for iFirma API
 */

class VAT {
    const VAT_0 = 0.00;
    const VAT_5 = 0.05;
    const VAT_8 = 0.08;
    const VAT_23 = 0.23;
}

class Address {
    public $city;
    public $zipCode;
    public $street;
    public $country;

    public function __construct($city, $zipCode, $street = null, $country = null) {
        $this->city = $city;
        $this->zipCode = $zipCode;
        $this->street = $street;
        $this->country = $country;
    }
}

class Client {
    public $name;
    public $taxId;
    public $address;
    public $email;
    public $phoneNumber;

    public function __construct($name, $taxId, Address $address, $email = null, $phoneNumber = null) {
        $this->name = $name;
        $this->taxId = $taxId;
        $this->address = $address;
        $this->email = $email;
        $this->phoneNumber = $phoneNumber;
    }

    public function toArray() {
        return [
            'Nazwa' => $this->name,
            'NIP' => $this->taxId,
            'KodPocztowy' => $this->address->zipCode,
            'Ulica' => $this->address->street,
            'Miejscowosc' => $this->address->city,
            'Kraj' => $this->address->country,
            'Email' => $this->email,
            'Telefon' => $this->phoneNumber,
        ];
    }
}

class Position {
    public $vatRate;
    public $quantity;
    public $basePrice;
    public $fullName;
    public $unit;
    public $pkwiu;
    public $discountPercent;

    public function __construct($vatRate, $quantity, $basePrice, $fullName, $unit, $pkwiu = null, $discountPercent = null) {
        $this->vatRate = $vatRate;
        $this->quantity = $quantity;
        $this->basePrice = $basePrice;
        $this->fullName = $fullName;
        $this->unit = $unit;
        $this->pkwiu = $pkwiu;
        $this->discountPercent = $discountPercent;
    }

    public function toArray() {
        return [
            'StawkaVat' => $this->vatRate,
            'Ilosc' => $this->quantity,
            'CenaJednostkowa' => $this->basePrice,
            'NazwaPelna' => $this->fullName,
            'Jednostka' => $this->unit,
            'TypStawkiVat' => 'PRC',
            'Rabat' => $this->discountPercent
        ];
    }
}

class NewInvoiceParams {
    public $client;
    public $positions;
    public $issueDate;

    public function __construct(Client $client, array $positions) {
        $this->client = $client;
        $this->positions = $positions;
        $this->issueDate = new DateTime();
    }

    private function getIssueDate() {
        return $this->issueDate->format('Y-m-d');
    }

    private function getTotalPrice() {
        $total = 0;
        foreach ($this->positions as $position) {
            $price = $position->quantity * $position->basePrice;
            if ($position->discountPercent) {
                $price *= (1 - $position->discountPercent / 100);
            }
            $total += $price;
        }
        return $total;
    }

    public function getRequestData() {
        $totalPrice = $this->getTotalPrice();
        $currentDate = date('Y-m-d'); // Current date in YYYY-MM-DD format
        
        $positionsArray = [];
        foreach ($this->positions as $position) {
            $positionsArray[] = $position->toArray();
        }
        
        return [
            'Zaplacono' => $totalPrice,
            'ZaplaconoNaDokumencie' => $totalPrice,
            'LiczOd' => 'BRT',
            'DataWystawienia' => $currentDate,
            'DataSprzedazy' => $currentDate,
            'FormatDatySprzedazy' => 'MSC',
            'SposobZaplaty' => 'P24',
            'RodzajPodpisuOdbiorcy' => 'BPO',
            'WidocznyNumerGios' => false,
            'Numer' => null,
            'Pozycje' => $positionsArray,
            'Kontrahent' => $this->client->toArray(),
        ];
    }
}

class iFirmaAPI {
    private $username;
    private $invoiceKeyName = 'faktura';
    private $invoiceKeyValue;
    private $userKeyName = 'abonent';
    private $userKeyValue;

    public function __construct($username, $invoiceKeyValue, $userKeyValue = null) {
        $this->username = $username;
        $this->invoiceKeyValue = $this->unhexKeyValue($invoiceKeyValue);
        
        if ($userKeyValue) {
            $this->userKeyValue = $this->unhexKeyValue($userKeyValue);
        }
    }

    private function unhexKeyValue($text) {
        $result = @hex2bin($text);
        if ($result === false) {
            throw new Exception("Invalid hex value");
        }
        return $result;
    }

    private function getHmacOfText($key, $text) {
        return hash_hmac('sha1', $text, $key);
    }

    private function createAuthenticationHeaderValue($requestHashText) {
        return "IAPIS user={$this->username}, hmac-sha1=" . $this->getHmacOfText($this->invoiceKeyValue, $requestHashText);
    }

    private function executePostRequest($headers, $requestContent, $url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestContent);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new Exception('Curl error: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        $responseData = json_decode($response, true);
        
        if (!isset($responseData['response'])) {
            throw new Exception('Unknown error', -1);
        }
        
        $responseContent = $responseData['response'];
        $responseCode = isset($responseContent['Kod']) ? $responseContent['Kod'] : -1;
        
        if ($responseCode !== 0) {
            $this->throwExceptionByCode($responseCode);
        }
        
        return $responseData;
    }

    private function throwExceptionByCode($code) {
        switch ($code) {
            case 201:
                throw new Exception('Bad request parameters', 201);
            case 400:
                throw new Exception('Bad request structure', 400);
            default:
                throw new Exception('Unknown error', -1);
        }
    }

    private function createInvoiceAndReturnId(NewInvoiceParams $invoice, $url) {
        $requestContent = json_encode($invoice->getRequestData(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $requestHashText = $url . $this->username . $this->invoiceKeyName . $requestContent;
        
        $headers = [
            'Accept: application/json',
            'Content-type: application/json; charset=UTF-8',
            'Authentication: ' . $this->createAuthenticationHeaderValue($requestHashText)
        ];
        
        $responseData = $this->executePostRequest($headers, $requestContent, $url);
        
        return isset($responseData['response']['Identyfikator']) ? $responseData['response']['Identyfikator'] : null;
    }

    public function generateInvoice(NewInvoiceParams $invoice) {
        $url = 'https://www.ifirma.pl/iapi/fakturakraj.json';
        $invoiceId = $this->createInvoiceAndReturnId($invoice, $url);
        
        if ($invoiceId) {
            $invoiceNumber = $this->getInvoiceNumber($invoiceId);
            return [$invoiceId, $invoiceNumber];
        }
        
        return [null, null];
    }

    public function getInvoiceNumber($invoiceId) {
        $url = "https://www.ifirma.pl/iapi/fakturakraj/{$invoiceId}.json";
        $requestHashText = $url . $this->username . $this->invoiceKeyName;
        
        $headers = [
            'Accept: application/json',
            'Content-type: application/json; charset=UTF-8',
            'Authentication: ' . $this->createAuthenticationHeaderValue($requestHashText)
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $responseData = json_decode($response, true);
        return $responseData['response']['PelnyNumer'];
    }

    public function getInvoicePdf($invoiceId) {
        $url = "https://www.ifirma.pl/iapi/fakturakraj/{$invoiceId}.pdf";
        $requestHashText = $url . $this->username . $this->invoiceKeyName;
        
        $headers = [
            'Accept: application/pdf',
            'Content-type: application/pdf; charset=UTF-8',
            'Authentication: ' . $this->createAuthenticationHeaderValue($requestHashText)
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $content = curl_exec($ch);
        curl_close($ch);
        
        return $content;
    }
}

/**
 * Example usage:
 * 
 * $ifirmaClient = new iFirmaAPI("$DEMO254343", "C501C88284462384", "B83E825D4D28BD11");
 * 
 * $client = new Client(
 *     "Dariusz Aniszewski's Company",  // company name
 *     "PL1231231212",  // Tax ID
 *     new Address(
 *         "Otwock",  // City
 *         "00-000"  // Zip code
 *     ),
 *     "email@server.com"  // Email
 * );
 * 
 * $position = new Position(
 *     VAT::VAT_23,  // VAT rate 
 *     1,  // Quantity
 *     1000.00,  // Unit total price
 *     "nazwa",  // Position name
 *     "szt"  // Position unit
 * );
 * 
 * $invoice = new NewInvoiceParams($client, [$position]);
 * list($invoiceId, $invoiceNumber) = $ifirmaClient->generateInvoice($invoice);
 * 
 * $pdfContent = $ifirmaClient->getInvoicePdf($invoiceId);
 * file_put_contents("invoice_{$invoiceId}.pdf", $pdfContent);
 */