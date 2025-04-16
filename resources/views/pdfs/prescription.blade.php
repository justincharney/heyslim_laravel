<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Prescription #{{ $prescription->id }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .section {
            margin-bottom: 15px;
        }
        .medication-section {
            border: 1px solid #000;
            padding: 10px;
            margin-bottom: 20px;
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
            background-color: #f2f2f2;
        }
        .initial-dose {
            background-color: #e6f7ff;
        }
        .maintenance-dose {
            background-color: #e6ffe6;
        }
        .signature-line {
            margin-top: 40px;
            border-top: 1px solid #000;
            padding-top: 5px;
            width: 80%;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Prescription</h1>
        <p>Date: {{ now()->format('d/m/Y') }}</p>
    </div>

    <div class="section">
        <h3>Patient Information</h3>
        <p>Name: {{ $patient->name }}</p>
        <p>Email: {{ $patient->email }}</p>
        <p>DOB: {{ $patient->dob->format('d/m/Y') }}</p>
        <p>Address: {{ $patient->address }}</p>
    </div>

    <div class="section">
        <h3>Prescriber Information</h3>
        <p>Name: {{ $prescriber->name }}</p>
        <p>Registration Number: {{ $prescriber->registration_number }}</p>
    </div>

    <div class="medication-section">
        <h3>Medication</h3>
        <p><strong>Name:</strong> {{ $prescription->medication_name }}</p>
        <p><strong>Administration:</strong> {{ $prescription->dose_schedule['administration_route'] ?? 'As directed' }}</p>
        <p><strong>Frequency:</strong> {{ $prescription->dose_schedule['frequency'] ?? $prescription->schedule }}</p>

        <h4>Dosing Schedule</h4>
        <table class="dose-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Dose</th>
                    <th>Duration</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                @if(isset($prescription->dose_schedule['doses']) && is_array($prescription->dose_schedule['doses']))
                    @foreach($prescription->dose_schedule['doses'] as $index => $dose)
                        <tr class="{{ $index === 0 ? 'initial-dose' : ($dose['description'] == 'Maintenance dose' ? 'maintenance-dose' : '') }}">
                            <td>{{ $dose['dose_number'] ?? ($index + 1) }}</td>
                            <td>{{ $dose['amount'] }} {{ $prescription->dose_schedule['dose_unit'] ?? 'mg' }}</td>
                            <td>
                                @if(isset($dose['duration_weeks']) && $dose['duration_weeks'])
                                    {{ $dose['duration_weeks'] }} weeks
                                @else
                                    Ongoing (maintenance)
                                @endif
                            </td>
                            <td>{{ $dose['description'] ?? '' }}</td>
                        </tr>
                    @endforeach
                @endif
            </tbody>
        </table>

        <p><strong>Total Refills:</strong> {{ $prescription->refills }}</p>
        <p><strong>Directions:</strong> {{ $prescription->directions }}</p>

        @if(isset($prescription->dose_schedule['special_instructions']))
            <p><strong>Special Instructions:</strong> {{ $prescription->dose_schedule['special_instructions'] }}</p>
        @endif

        <p><strong>Start Date:</strong> {{ $prescription->start_date->format('d/m/Y') }}</p>
        @if($prescription->end_date)
            <p><strong>End Date:</strong> {{ $prescription->end_date->format('d/m/Y') }}</p>
        @endif
    </div>

    <div class="section">
        <div class="signature-line">
            Prescriber Signature
        </div>
    </div>
</body>
</html>
