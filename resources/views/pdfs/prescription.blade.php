<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Prescription #{{ $prescription->id }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 30px;
            font-size: 12px;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #2c3e50;
            padding-bottom: 10px;
        }
        .header h1 {
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .logo {
            max-width: 180px;
            margin-bottom: 10px;
        }
        .section {
            margin-bottom: 25px;
        }
        .section h3 {
            color: #2c3e50;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
            margin-bottom: 10px;
        }
        .medication-section {
            border: 2px solid #2c3e50;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 25px;
            background-color: #f9f9f9;
        }
        .dose-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        .dose-table th, .dose-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .dose-table th {
            background-color: #2c3e50;
            color: white;
        }
        .current-dose {
            background-color: #e6f7ff;
            font-weight: bold;
        }
        .info-row {
            display: flex;
            flex-wrap: wrap;
        }
        .info-item {
            width: 50%;
            margin-bottom: 8px;
        }
        .signature-section {
            margin-top: 40px;
        }
        .signature-line {
            border-top: 1px solid #000;
            padding-top: 5px;
            width: 80%;
            margin-top: 40px;
        }
        .rx-symbol {
            font-size: 18px;
            font-weight: bold;
            margin-right: 5px;
        }
        .prescription-id {
            text-align: right;
            font-size: 10px;
            color: #666;
            margin-bottom: 10px;
        }
        .watermark {
            position: fixed;
            top: 40%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 60px;
            color: rgba(200, 200, 200, 0.2);
            z-index: -1;
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>PRESCRIPTION</h1>
        <p>Date Issued: {{ $prescription->created_at->format('d/m/Y') }}</p>
        <div class="prescription-id">Prescription ID: {{ $prescription->id }}</div>
    </div>

    <div class="section">
        <h3>Patient Information</h3>
        <div class="info-row">
            <div class="info-item"><strong>Name:</strong> {{ $patient->name }}</div>
            <div class="info-item"><strong>DOB:</strong> {{ $patient->date_of_birth->format('d/m/Y') }}</div>
            <div class="info-item"><strong>Email:</strong> {{ $patient->email }}</div>
            <div class="info-item"><strong>Address:</strong> {{ $patient->address }}</div>
        </div>
    </div>

    <div class="section">
        <h3>Prescriber Information</h3>
        <div class="info-row">
            <div class="info-item"><strong>Name:</strong> {{ $prescriber->name }}</div>
            <div class="info-item"><strong>Registration Number:</strong> {{ $prescriber->registration_number }}</div>
        </div>
    </div>

    <div class="medication-section">
        <h3><span class="rx-symbol">&#8478;</span> Medication</h3>

        @php
          $schedule   = $prescription->dose_schedule ?? [];
          $maxRefill  = collect($schedule)->max('refill_number') ?? 0;
          $remaining  = $prescription->refills;
          $usedSoFar  = $maxRefill - $remaining;

          // find that entry, or fallback
          $entry = collect($schedule)->firstWhere('refill_number', $usedSoFar);
          $currentDose = $entry['dose'] ?? $prescription->dose;
        @endphp

        <div class="info-row">
            <div class="info-item"><strong>Medication Name:</strong> {{ $prescription->medication_name }}</div>
            <div class="info-item"><strong>Refills:</strong> {{ $remaining }}</div>

            <div class="info-item">
              <strong>Dose:</strong>
              {{ $currentDose }}
            </div>

            <div class="info-item"><strong>Start Date:</strong> {{ $prescription->start_date->format('d/m/Y') }}</div>
            <div class="info-item"><strong>Expiry Date:</strong> {{ $prescription->end_date ? $prescription->end_date->format('d/m/Y') : 'N/A' }}</div>
        </div>

        <div style="margin-top: 15px;">
            <strong>Directions:</strong> {{ $prescription->directions }}
        </div>

        <!-- @if (!empty($prescription->dose_schedule) && isset($prescription->dose_schedule['doses']) && count($prescription->dose_schedule['doses']) > 1)
            <h4 style="margin-top: 20px;">Dose Schedule</h4>
            <table class="dose-table">
                <thead>
                    <tr>
                        <th>Refill #</th>
                        <th>Dose</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($prescription->dose_schedule['doses'] as $index => $doseInfo)
                        <tr class="{{ $index == $currentRefill ? 'current-dose' : '' }}">
                            <td>{{ $index }}</td>
                            <td>{{ is_array($doseInfo) ? $doseInfo['dose'] : $doseInfo }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif -->
    </div>

    <div class="signature-section">

        <div class="signature-line">
            Prescriber Signature:
        </div>
    </div>
</body>
</html>
