<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>GP Information Letter</title>
    <style>
        body { font-family: sans-serif; margin: 20px; color: #333; font-size: 10pt; }
        .header, .footer { text-align: center; margin-bottom: 15px; }
        .clinic-info { text-align: right; margin-bottom: 15px; font-size: 9pt; }
        .patient-info, .prescriber-info { margin-bottom: 15px; }
        .section-title { font-weight: bold; margin-top: 15px; margin-bottom: 5px; font-size: 11pt; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top;}
        th { background-color: #f2f2f2; }
        .emphasize { font-weight: bold; }
        .small-text { font-size: 9pt; }
        .conditions-list, .conditions-options-list { list-style-type: disc; padding-left: 20px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ config('app.title', 'HeySlim Clinic') }}</h1>
        <p>email: support@heyslim.co.uk</p>
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

    <p>We are writing to inform you that your patient, <span class="emphasize">{{ $patient->name }}</span>, has commenced private weight management treatment under our care at HeySlim.</p>

    <p>Following a comprehensive consulation and assement, we have initiated therapy with the following medication:</p>

    <div class="section-title">Prescription Details</div>
    <table>
        <tr><th>Medication:</th><td>{{ $prescription->medication_name }} {{$prescription->dose_schedule[0]['dose']}}</td></tr>
        <tr><th>Prescriber:</th><td>{{ $prescriber->name }} (Reg No: {{ $prescriber->registration_number ?? 'N/A' }})</td></tr>
        <tr><th>Date of Issue:</th><td>{{ $prescription->created_at->format('d/m/Y') }}</td></tr>
    </table>

    <!-- <div class="section-title">Dose Schedule</div>
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
    @endif -->

    <p>They have provided us with the following measurements:
        Height: <span class="emphasize">{{ $answers['height'] ?? 'N/A' }} cm</span>,
           Weight: <span class="emphasize">{{ $answers['weight'] ?? 'N/A' }} kg</span>.
           Calculated BMI: <span class="emphasize">{{ $answers['bmi'] ?? 'N/A' }}</span>.
    </p>



    <p>They have advised us that they are registered to your surgery and have provided the following information regarding their medical history:</p>

    <p><span class="emphasize">Relevant medical conditions they have or have ever had:</span></p>
       @php
           // $answers['conditions'] is expected to be an array of selected condition strings from the Job.
           // $answers['conditions_options'] is a collection/array of available option strings from the Job.
           $patientSelectedConditions = $answers['conditions'] ?? [];
           $availableOptions = $answers['conditions_options'] ?? collect();
       @endphp

       @if($availableOptions->isNotEmpty())
           <p>The patient was presented with the following options for medical conditions and responded as indicated:</p>
           <ul class="conditions-options-list">
           @foreach($availableOptions as $optionText)
               <li>
                   {{ $optionText }} -
                   @if(is_array($patientSelectedConditions) && in_array($optionText, $patientSelectedConditions))
                       <span class="emphasize">Selected</span>
                   @else
                       Not Selected
                   @endif
               </li>
           @endforeach
           </ul>
        @endif
    <!-- @php
        $conditionsDisplay = $answers['conditions'] ?? 'Information not provided or N/A.';
    @endphp
    @if(is_array($conditionsDisplay) && !empty($conditionsDisplay))
        <ul class="conditions-list">
        @foreach($conditionsDisplay as $condition)
            <li>{{ $condition }}</li>
        @endforeach
        </ul>
    @elseif(is_string($conditionsDisplay) && !empty(trim($conditionsDisplay)) && $conditionsDisplay !== 'Not specified'  && $conditionsDisplay !== 'Information not provided or N/A.')
        <p>{{ $conditionsDisplay }}</p>
    @else
        <p>None disclosed or N/A.</p>
    @endif -->

    <p><span class="emphasize">Are you pregnant, planning to become pregnant, or currently breastfeeding?</span></p>
    <p>{{ $answers['pregnancy'] ?? 'Information not provided or N/A.' }}</p>

    <p><span class="emphasize">Have you had any bariatric (weight loss) surgery?</span></p>
    <p>{{ $answers['bariatric_surgery'] ?? 'Information not provided or N/A.' }}</p>

    <p>If you are aware the information that they have provided may be inaccurate we would greatly appreciate it if you could contact us at support@heyslim.co.uk, so that we can discuss next steps with the patient.</p>

    <p>We do recognise your workload and thank you for taking the time to read this letter and responding if you feel it is necessary. Please don’t hesitate to contact us if you have any questions.</p>

    <!-- <div class="section-title">Clinical Information</div>
    @if($clinicalPlan)
        <table>
            <tr><th>Condition Treated:</th><td>{{ $clinicalPlan->condition_treated ?: 'N/A' }}</td></tr>
            <tr><th>Monitoring:</th><td>{{ $clinicalPlan->monitoring_frequency ?: 'As per standard practice' }}</td></tr>
        </table>
    @else
        <p>Associated clinical plan information not available.</p>
    @endif

    <p>This treatment was initiated following an assessment conducted via our platform. We encourage open communication for continuity of care.</p> -->

    <div class="signature-section">
        <p>Sincerely,</p><br>
        <p>{{ $prescriber->name }}</p>
        <p>For {{ config('app.title', 'HeySlim Clinic') }}</p>
    </div>

    <!-- <div class="footer small-text" style="margin-top: 30px; text-align: center;">
        <p>This letter is for informational purposes for the patient's General Practitioner.</p>
    </div> -->
</body>
</html>
