<?php

namespace App\Commands;

use App\Libraries\ReceivableReminderPolicy;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

class SendReceivableReminders extends BaseCommand
{
    protected $group       = 'Receivables';
    protected $name        = 'receivables:remind';
    protected $description = 'Send daily in-app receivable reminders to active Owners and Cashiers.';
    protected $usage       = 'receivables:remind';

    public function run(array $params)
    {
        $timezone = new DateTimeZone('Asia/Jakarta');
        $today = ReceivableReminderPolicy::todayInJakarta();
        $db = db_connect();

        $userRows = $db->query(
            "SELECT pengguna.user_id
             FROM pengguna
             JOIN peran ON peran.role_id = pengguna.role_id
             WHERE pengguna.is_active = 1
               AND peran.role_code IN ('OWNER', 'KASIR')
             ORDER BY pengguna.user_id",
        )->getResultArray();
        if ($userRows === []) {
            CLI::error('No active OWNER or KASIR accounts are available for receivable reminders.');

            return EXIT_ERROR;
        }
        $userIds = array_map(static fn (array $user): int => (int) $user['user_id'], $userRows);

        $candidateRows = $db->query(
            "SELECT receivable_id
             FROM piutang
             WHERE status IN ('OPEN', 'PARTIALLY_PAID')
               AND due_date IS NOT NULL
               AND due_date <= ?
             ORDER BY due_date, receivable_id",
            [$today->modify('+' . ReceivableReminderPolicy::DAYS_BEFORE_DUE . ' days')->format('Y-m-d')],
        )->getResultArray();

        $reminderCount = 0;
        $notificationCount = 0;
        $categoryLabels = [
            'overdue' => 'Terlambat',
            'due_today' => 'Jatuh tempo hari ini',
            'h3' => 'Jatuh tempo H-3',
        ];

        foreach ($candidateRows as $candidate) {
            if (! $db->transBegin()) {
                throw new RuntimeException('Could not start a transaction for a receivable reminder.');
            }

            try {
                $receivableId = (int) $candidate['receivable_id'];
                $receivable = $db->query(
                    'SELECT receivable_id, customer_id, sale_id, original_amount, due_date, status
                     FROM piutang
                     WHERE receivable_id = ?
                     FOR UPDATE',
                    [$receivableId],
                )->getRowArray();

                if ($receivable === null
                    || ! in_array($receivable['status'], ['OPEN', 'PARTIALLY_PAID'], true)
                    || $receivable['due_date'] === null) {
                    if (! $db->transCommit()) {
                        throw new RuntimeException('Could not finish a skipped receivable reminder transaction.');
                    }
                    continue;
                }

                $balance = $db->query(
                    "SELECT GREATEST(
                                ? - COALESCE((SELECT SUM(amount) FROM pembayaran_piutang WHERE receivable_id = ?), 0.00)
                                  - COALESCE((SELECT SUM(amount) FROM penyesuaian_piutang WHERE receivable_id = ?), 0.00),
                                0.00
                            ) AS outstanding_amount",
                    [(string) $receivable['original_amount'], $receivableId, $receivableId],
                )->getRowArray();
                if ($balance === null) {
                    throw new RuntimeException('Could not calculate the current receivable balance.');
                }

                $outstandingAmount = (float) $balance['outstanding_amount'];
                $category = ReceivableReminderPolicy::category(
                    $receivable['due_date'],
                    $outstandingAmount,
                    $today,
                );
                if ($category === null) {
                    if (! $db->transCommit()) {
                        throw new RuntimeException('Could not finish a skipped receivable reminder transaction.');
                    }
                    continue;
                }

                if (! $db->query(
                    'INSERT IGNORE INTO pengingat_piutang_harian
                        (receivable_id, reminder_category, reminder_date)
                     VALUES (?, ?, ?)',
                    [$receivableId, $category, $today->format('Y-m-d')],
                )) {
                    throw new RuntimeException('Could not record the daily receivable reminder.');
                }
                if ($db->affectedRows() === 0) {
                    if (! $db->transCommit()) {
                        throw new RuntimeException('Could not finish a duplicate receivable reminder transaction.');
                    }
                    continue;
                }

                $invoice = $db->table('penjualan')
                    ->select('invoice_number')
                    ->where('sale_id', $receivable['sale_id'])
                    ->get()
                    ->getRowArray();
                $customer = $db->table('pelanggan')
                    ->select('customer_name')
                    ->where('customer_id', (int) $receivable['customer_id'])
                    ->get()
                    ->getRowArray();
                if ($customer === null) {
                    throw new RuntimeException('The customer for a receivable reminder was not found.');
                }

                $categoryLabel = $categoryLabels[$category];
                $invoiceNumber = $invoice['invoice_number'] ?? 'Saldo piutang awal';
                $dueDate = new DateTimeImmutable($receivable['due_date'], $timezone);
                $message = sprintf(
                    'Pelanggan: %s | Faktur: %s | Jatuh tempo: %s | Sisa tagihan: Rp %s | Kategori: %s',
                    $customer['customer_name'],
                    $invoiceNumber,
                    $dueDate->format('d/m/Y'),
                    number_format($outstandingAmount, 2, ',', '.'),
                    $categoryLabel,
                );

                foreach ($userIds as $userId) {
                    if (! $db->table('notifikasi_pengguna')->insert([
                        'user_id' => $userId,
                        'notification_type' => 'RECEIVABLE_REMINDER',
                        'title' => 'Pengingat piutang: ' . $categoryLabel,
                        'message' => $message,
                        'target_url' => 'piutang',
                    ])) {
                        throw new RuntimeException('Could not save an in-app receivable reminder notification.');
                    }
                }

                if ($db->transStatus() === false) {
                    throw new RuntimeException('The receivable reminder transaction failed.');
                }
                if (! $db->transCommit()) {
                    throw new RuntimeException('Could not commit a receivable reminder transaction.');
                }

                $reminderCount++;
                $notificationCount += count($userIds);
            } catch (Throwable $exception) {
                $db->transRollback();
                throw $exception;
            }
        }

        CLI::write(
            sprintf(
                'Asia/Jakarta %s: %d reminder(s) sent to %d in-app account notification(s).',
                $today->format('Y-m-d'),
                $reminderCount,
                $notificationCount,
            ),
            'green',
        );

        return EXIT_SUCCESS;
    }
}
