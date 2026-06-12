<?php

namespace App\Http\Controllers;

use App\Services\Reports\ReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * CSV export of transactions for the selected period (module 9). Gated export_data.
     * Mirrors the dashboard view — same period, no row cap.
     */
    public function exportTransactions(Request $request, ReportService $reports): StreamedResponse
    {
        $period = $request->input('period');
        if (! in_array($period, ReportService::PERIODS, true)) {
            $period = 'all';
        }

        $user = $request->user();
        $scopeId = $user->seesAllData() ? null : $user->id;

        $rows = $reports->transactions($period, null, $scopeId);
        $filename = "transactions-{$period}-".now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Txn ID', 'Date', 'Client', 'Serial', 'Amount', 'Method', 'Status']);

            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['txn_id'],
                    $r['date'],
                    $r['client_name'],
                    $r['serial_no'],
                    number_format($r['amount'], 2, '.', ''),
                    $r['method'],
                    $r['status'],
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
