<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <style>
        @page {
            size: 58mm 40mm; /* Стандартный размер термоэтикетки */
            margin: 0;
        }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            margin: 0;
            padding: 2mm;
            text-align: center;
            overflow: hidden;
        }
        .product-name {
            font-size: 10px;
            font-weight: bold;
            height: 8mm;
            line-height: 1.1;
            margin-bottom: 1mm;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .price {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 1mm;
        }
        .price span {
            font-size: 10px;
            font-weight: normal;
        }
        .barcode-container {
            margin-top: 1mm;
        }
        .barcode-container img {
            width: 100%;
            height: 12mm;
        }
        .sku-text {
            font-size: 9px;
            margin-top: 1mm;
            letter-spacing: 2px;
        }
    </style>
</head>
<body>
    <div class="product-name">{{ $product->name }}</div>

    <div class="price">
        {{ number_format($product->price, 0, '.', ' ') }} <span>с</span>
    </div>

    <div class="barcode-container">
        @php
            $generator = new \Picqer\Barcode\BarcodeGeneratorPNG();
            $barcodeData = base64_encode($generator->getBarcode($product->sku, $generator::TYPE_CODE_128));
        @endphp
        <img src="data:image/png;base64,{{ $barcodeData }}">
    </div>

    <div class="sku-text">{{ $product->sku }}</div>
</body>
</html>
