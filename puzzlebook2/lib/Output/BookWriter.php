<?php

declare(strict_types=1);

namespace Puzzlebook2\Output;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\ChainPlan;
use Puzzlebook2\Solver\FilledBoard;

/**
 * Writes exactly the artifacts v1's bin/build.php wrote, byte-compatible in
 * schema: build/book.json ({grid:{pages,rows,cols}, words}), build/chain.json
 * (the numbered step list + built_at), build/pages.txt (a plain-text proof of
 * every page), build/eliminated/ (one file per step listing what it removed,
 * plus summary.txt) -- and copies of book.json/chain.json into web/ for the
 * preview page to fetch. The existing web/index.html needs no changes.
 */
final class BookWriter
{
    /**
     * @param array{ok:bool, lines:string[], eliminated:array<int,string[]>} $verification
     * @return string[] paths written
     */
    public static function write(string $root, FilledBoard $board, ChainPlan $plan, array $verification): array
    {
        $spec = $board->spec();
        $book = [
            'grid' => ['pages' => $spec->pages, 'rows' => $spec->rows, 'cols' => $spec->cols],
            'words' => array_values($board->allWords()),
        ];

        $chainForExport = $plan->chainConfig();
        $chainForExport['built_at'] = date('D j M Y, H:i');

        $bookJson = json_encode($book, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $chainJson = json_encode($chainForExport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        $written = [];
        foreach (['build', 'build/eliminated', 'web'] as $dir) {
            if (!is_dir("{$root}/{$dir}")) {
                mkdir("{$root}/{$dir}", 0777, true);
            }
        }

        file_put_contents($written[] = "{$root}/build/book.json", $bookJson);
        file_put_contents($written[] = "{$root}/build/chain.json", $chainJson);
        file_put_contents($written[] = "{$root}/build/pages.txt", self::renderPages($spec, $book['words']));
        file_put_contents($written[] = "{$root}/web/book.json", $bookJson);
        file_put_contents($written[] = "{$root}/web/chain.json", $chainJson);

        $stepById = [];
        foreach ($plan->steps() as $pos => $step) {
            $stepById[$step['id']] = $plan->rules()[$pos];
        }
        foreach ($verification['eliminated'] as $stepId => $removedWords) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $stepById[$stepId]->describe()));
            $slug = substr(trim($slug, '-'), 0, 40);
            sort($removedWords);
            $file = sprintf('%s/build/eliminated/step-%02d-%s.txt', $root, $stepId, $slug);
            file_put_contents($written[] = $file, implode("\n", $removedWords) . "\n");
        }
        file_put_contents(
            $written[] = "{$root}/build/eliminated/summary.txt",
            implode("\n", $verification['lines']) . "\n",
        );

        return $written;
    }

    /** @param string[] $words */
    private static function renderPages(BoardSpec $spec, array $words): string
    {
        $colWidth = 8 + max(array_map('strlen', $words));
        $out = '';
        for ($page = 0; $page < $spec->pages; $page++) {
            $out .= '=== Page ' . ($page + 1) . " ===\n";
            for ($row = 0; $row < $spec->rows; $row++) {
                $line = '';
                for ($col = 0; $col < $spec->cols; $col++) {
                    $line .= str_pad($words[$spec->linearIndex($page, $row, $col)], $colWidth);
                }
                $out .= rtrim($line) . "\n";
            }
            $out .= "\n";
        }
        return $out;
    }
}
