<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a form's responses as a CSV file that opens in Excel, Google Sheets or Numbers.
 */
class FormSubmissionExport
{
    public function __construct(private FormSchema $schema) {}

    /**
     * Columns: submitted at, account email, then one per field. Answers to fields that were later removed
     * from the form are kept in extra columns at the end.
     */
    public function download(Form $form): StreamedResponse
    {
        $columns = $this->columns($form);
        $filename = Str::slug($form->title ?: 'form').'-responses-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($form, $columns) {
            $output = fopen('php://output', 'w');
            // Byte order mark so Excel reads the file as UTF-8 (names such as Adébáyọ̀ stay intact).
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $this->safeRow([__('Submitted at'), __('Account email'), ...array_values($columns)]), escape: '');

            $form->submissions()->with('user:id,email')->oldest()->lazy(500)->each(function (FormSubmission $submission) use ($output, $columns) {
                $answers = array_map(fn (string $key) => $this->schema->displayValue($submission->data[$key] ?? null), array_keys($columns));

                fputcsv($output, $this->safeRow([
                    $submission->created_at?->toDateTimeString() ?? '',
                    $submission->user?->email ?? '',
                    ...$answers,
                ]), escape: '');
            });

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Field key => column heading: the current fields first, then keys only found in older responses.
     *
     * @return array<string, string>
     */
    public function columns(Form $form): array
    {
        $columns = collect($form->inputFields())->mapWithKeys(fn (array $field) => [$field['key'] => $field['label']])->all();

        $form->submissions()->select(['id', 'data'])->lazy(500)->each(function (FormSubmission $submission) use (&$columns) {
            foreach (array_keys($submission->data ?? []) as $key) {
                $columns[$key] ??= $key.' '.__('(removed)');
            }
        });

        return $columns;
    }

    /**
     * Stop spreadsheet apps from running answers that start with =, +, -, @ as formulas (CSV injection).
     *
     * @param  list<string>  $row
     * @return list<string>
     */
    private function safeRow(array $row): array
    {
        return array_map(
            fn (string $cell) => $cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$cell : $cell,
            $row,
        );
    }
}
