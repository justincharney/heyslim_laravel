<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Prescription Label #{{ $prescription->id }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 4px;
            font-size: 8px;
            color: #000;
            width: 70mm;
            height: 35mm;
            overflow: hidden;
        }
        .prescription-container {
            border: 1px solid #000;
            padding: 3px;
            width: 100%;
            height: 100%;
            box-sizing: border-box;
            position: relative;
        }
        .patient-info {
            margin-bottom: 2px;
            font-weight: bold;
            font-size: 9px;
        }
        .medication-info {
            margin-bottom: 2px;
            font-weight: bold;
            font-size: 9px;
        }
        .medication-details {
            margin-bottom: 4px;
            font-size: 8px;
        }
        .instructions {
            margin-bottom: 4px;
            font-size: 8px;
            border-top: 1px dashed #000;
            border-bottom: 1px dashed #000;
            padding: 2px 0;
        }
        .prescription-details {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2px;
            font-size: 7px;
        }
        .pharmacy-details {
            font-size: 7px;
        }
        .barcode {
            position: absolute;
            right: 3px;
            top: 10px;
            width: 20mm;
            height: 15mm;
            text-align: right;
        }
        .medication-qty {
            text-align: right;
            font-weight: bold;
            font-size: 8px;
            margin-top: -10px;
        }
        .patient-address {
            text-align: right;
            font-size: 7px;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <div class="prescription-container">
        <div class="patient-info">{{ $patient->name }}</div>
        <div class="patient-address">{{ $patient->address }}</div>

        <div class="medication-info">{{ $prescription->medication_name }}</div>

        <div class="prescription-details">
            @php
                $schedule   = $prescription->dose_schedule ?? [];
                $maxRefill  = collect($schedule)->max('refill_number') ?? 0;
                $remaining  = $prescription->refills;
                $usedSoFar  = $maxRefill - $remaining;
                // find that entry, or fallback
                $entry = collect($schedule)->firstWhere('refill_number', $usedSoFar);
                $currentDose = $entry['dose'] ?? $prescription->dose;
            @endphp
            <div>{{ $currentDose }}</div>
        </div>

        <div class="instructions">{{ $prescription->directions }}</div>

        <div class="prescription-details">
            <div>NO REFILLS</div>
        </div>

        <div class="prescription-details">
            <div>Pres by: {{ $prescriber->name }}</div>
            <div>Registration # {{ $prescriber->registration_number }}</div>
        </div>
    </div>
</body>
</html>
