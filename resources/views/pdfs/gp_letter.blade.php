<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>GP Information Letter</title>
    <style>
        body { font-family: sans-serif; margin: 20px; color: #333; font-size: 10pt; }
        .header, .footer { text-align: center; margin-bottom: 20px; }
        .clinic-info { text-align: right; margin-bottom: 30px; font-size: 9pt; }
        .patient-info, .prescriber-info { margin-bottom: 20px; }
        .section-title { font-weight: bold; margin-top: 15px; margin-bottom: 5px; font-size: 11pt; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top;}
        th { background-color: #f2f2f2; }
        .emphasize { font-weight: bold; }
        .small-text { font-size: 9pt; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ config('app.title', 'HeySlim Clinic') }}</h1>
        {{-- Add clinic address and contact if desired --}}
    </div>

    <div class="clinic-info">
        <p>{{ config('app.title', 'HeySlim Clinic') }}</p>
        <p>Date: {{ $currentDate }}</p>
    </div>

    <p>To the General Practitioner of:</p>
    <div class="patient-info">
        <span class="emphasize">{{ $patient->name }}</span><br>
        DOB: {{ $patient->date_of_birth ? $patient->date_of_birth->format('d/m/Y') : 'N/A' }}<br>
        Address: {{ $patient->address ?? 'N/A' }}
    </div>

    <p>Dear Doctor,</p>

    <p>We are writing to inform you that your patient, <span class="emphasize">{{ $patient->name }}</span>, has been prescribed the following medication through our service:</p>

    <div class="section-title">Prescription Details</div>
    <table>
        <tr><th>Medication:</th><td>{{ $prescription->medication_name }}</td></tr>
        <tr><th>Prescriber:</th><td>{{ $prescriber->name }} (Reg No: {{ $prescriber->registration_number ?? 'N/A' }})</td></tr>
        <tr><th>Date of Issue:</th><td>{{ $prescription->created_at->format('d/m/Y') }}</td></tr>
    </table>

    <div class="section-title">Dose Schedule</div>
    @if(!empty($prescription->dose_schedule) && is_array($prescription->dose_schedule))
        <table>
            <thead><tr><th>Stage/Month</th><th>Dose</th></tr></thead>
            <tbody>
                @foreach($prescription->dose_schedule as $index => $doseInfo)
                    <tr>
                        <td>{{ $doseInfo['description'] ?? ('Month ' . ($index + 1)) }}</td>
                        <td>{{ $doseInfo['dose'] ?? 'N/A' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p>Dose schedule not specified in this format.</p>
    @endif

    <div class="section-title">Clinical Information</div>
    @if($clinicalPlan)
        <table>
            <tr><th>Condition Treated:</th><td>{{ $clinicalPlan->condition_treated ?: 'N/A' }}</td></tr>
            <tr><th>Monitoring:</th><td>{{ $clinicalPlan->monitoring_frequency ?: 'As per standard practice' }}</td></tr>
        </table>
    @else
        <p>Associated clinical plan information not available.</p>
    @endif

    <p>This treatment was initiated following an assessment conducted via our platform. We encourage open communication for continuity of care.</p>

    <div class="signature-section" style="margin-top: 40px;">
        <p>Sincerely,</p><br><br>
        <p>{{ $prescriber->name }}</p>
        <p>For {{ config('app.title', 'HeySlim Clinic') }}</p>
    </div>

    <div class="footer small-text" style="margin-top: 30px; text-align: center;">
        <p>This letter is for informational purposes for the patient's General Practitioner.</p>
    </div>
</body>
</html>
