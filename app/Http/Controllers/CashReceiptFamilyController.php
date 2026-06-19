<?php

namespace App\Http\Controllers;

use App\Models\CashReceiptFamily;
use App\Models\CashReceiptType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CashReceiptFamilyController extends Controller
{



    public function update(Request $request, string $cashReceiptTypeUuid)
    {
        $validated = $request->validate([
            'families' => ['required', 'array', 'min:1', 'max:3'],
            'families.*.name' => ['required', 'string', 'max:255'],
        ]);

        DB::beginTransaction();

        try {

            $updatedBy = auth()->id();

            CashReceiptFamily::where('cash_receipt_type_uuid', $cashReceiptTypeUuid)
                ->delete();

            $data = [];

            foreach ($validated['families'] as $family) {

                $data[] = [
                    'uuid' => (string) Str::uuid(),
                    'name' => strtoupper($family['name']),
                    'code' => Str::slug($family['name'], '_'),
                    'cash_receipt_type_uuid' => $cashReceiptTypeUuid,
                    'created_by' => $updatedBy,
                    'updated_by' => $updatedBy,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            CashReceiptFamily::insert($data);
            CashReceiptType::where('uuid', $validated['cash_receipt_type_uuid'])
                ->update([
                    'have_family' => true,
                    'updated_by' => $updatedBy,
                    'updated_at' => now(),
                ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Familles mises à jour avec succès',
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
