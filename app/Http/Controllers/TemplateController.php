<?php

namespace App\Http\Controllers;

use App\Config\ShopifyProductMapping;
use App\Enums\Permission;
use App\Models\ClinicalPlanTemplate;
use App\Models\PrescriptionTemplate;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class TemplateController extends Controller
{
    use AuthorizesRequests;

    public function listClinicalPlanTemplates(Request $request)
    {
        $user = auth()->user();
        $teamId = $user->team_id;

        // Get templates: global ones + team-specific ones
        $templates = ClinicalPlanTemplate::where(function ($query) use (
            $teamId,
        ) {
            $query->where("is_global", true)->orWhere("team_id", $teamId);
        })->get();

        return response()->json([
            "templates" => $templates,
        ]);
    }

    public function listPrescriptionTemplates(Request $request)
    {
        $user = auth()->user();
        $teamId = $user->current_team_id;

        // Get templates: global ones + team-specific ones
        $templates = PrescriptionTemplate::where(function ($query) use (
            $teamId,
        ) {
            $query->where("is_global", true)->orWhere("team_id", $teamId);
        })->get();

        return response()->json([
            "templates" => $templates,
        ]);
    }

    public function listMedicationProducts(Request $request)
    {
        $medicationOptions = [];
        foreach (
            ShopifyProductMapping::$medicationProducts
            as $nameKey => $gid
        ) {
            $medicationOptions[] = [
                "name" => $nameKey,
                "gid" => $gid,
                "chargebee_item_price_id" => ShopifyProductMapping::getChargebeeItemPriceByProductGid(
                    $gid,
                ),
            ];
        }

        return response()->json([
            "medication_products" => $medicationOptions,
        ]);
    }

    public function getMedicationProductVariants(Request $request, $productGid)
    {
        $details = ShopifyProductMapping::getProductDetailsByGid($productGid);

        if (!$details || !isset($details["variants"])) {
            return response()->json(
                [
                    "message" =>
                        "Medication product or variants not found for GID: " .
                        $productGid,
                ],
                404,
            );
        }

        return response()->json([
            "variants" => $details["variants"],
        ]);
    }

    /*
    public function storeClinicalPlanTemplate(Request $request)
    {
        $this->authorize(Permission::WRITE_TREATMENT_PLANS);

        $validated = $request->validate([
            "name" => "required|string|max:255",
            "description" => "nullable|string",
            "condition_treated" => "required|string",
            "medicines_that_may_be_prescribed" => "required|string",
            "dose_schedule" => "required|string",
            "guidelines" => "required|string",
            "monitoring_frequency" => "required|string",
            "process_for_reporting_adrs" => "required|string",
            "is_global" => "boolean",
        ]);

        $user = auth()->user();
        $validated["created_by"] = $user->id;

        // Only admins can create global templates
        if (
            !$user->hasRole("admin") &&
            isset($validated["is_global"]) &&
            $validated["is_global"]
        ) {
            return response()->json(
                [
                    "message" =>
                        "Only administrators can create global templates",
                ],
                403
            );
        }

        // Set team ID for non-global templates
        if (!isset($validated["is_global"]) || !$validated["is_global"]) {
            $validated["team_id"] = $user->current_team_id;
        }

        $template = ClinicalPlanTemplate::create($validated);

        return response()->json(
            [
                "message" => "Template created successfully",
                "template" => $template,
            ],
            201
        );
    }

    public function getClinicalPlanTemplate($id)
    {
        $template = ClinicalPlanTemplate::findOrFail($id);

        // Check access: either global or in user's team
        $user = auth()->user();
        if (
            !$template->is_global &&
            $template->team_id !== $user->current_team_id
        ) {
            return response()->json(
                [
                    "message" => "Template not found in your team",
                ],
                404
            );
        }

        return response()->json([
            "template" => $template,
        ]);
    }

    // Could allow users to update their own clinical plan templates

    public function storePrescriptionTemplate(Request $request)
    {
        $this->authorize(Permission::WRITE_PRESCRIPTIONS);

        $validated = $request->validate([
            "name" => "required|string|max:255",
            "description" => "nullable|string",
            "medication_name" => "required|string|max:255",
            "dose" => "required|string",
            "schedule" => "required|string",
            "refills" => "required|integer|between:0,12",
            "directions" => "nullable|string",
            "is_global" => "boolean",
        ]);

        $user = auth()->user();
        $validated["created_by"] = $user->id;

        // Only admins can create global templates
        if (
            !$user->hasRole("admin") &&
            isset($validated["is_global"]) &&
            $validated["is_global"]
        ) {
            return response()->json(
                [
                    "message" =>
                        "Only administrators can create global templates",
                ],
                403
            );
        }

        // Set team ID for non-global templates
        if (!isset($validated["is_global"]) || !$validated["is_global"]) {
            $validated["team_id"] = $user->current_team_id;
        }

        $template = PrescriptionTemplate::create($validated);

        return response()->json(
            [
                "message" => "Template created successfully",
                "template" => $template,
            ],
            201
        );
    }

    public function getPrescriptionTemplate($id)
    {
        $template = PrescriptionTemplate::findOrFail($id);

        // Check access: either global or in user's team
        $user = auth()->user();
        if (
            !$template->is_global &&
            $template->team_id !== $user->current_team_id
        ) {
            return response()->json(
                [
                    "message" => "Template not found in your team",
                ],
                404
            );
        }

        return response()->json([
            "template" => $template,
        ]);
    }
    */
}
