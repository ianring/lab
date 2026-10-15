<?php
declare(strict_types=1);

/**
 * Shared HTML for the answer key and the solving walkthrough.
 *
 * Class names are prefixed pv- and the stylesheet is emitted alongside, so this
 * can be dropped into any page without colliding with its own CSS.
 */
final class PuzzleView
{
    private static function h($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }

    /** Stylesheet for the markup below. Box rules follow the variant geometry. */
    public static function css(Variant $variant): string
    {
        return <<<CSS
  .pv-grid { border-collapse: collapse; background: #fff; border: 2.5px solid #1c1c1e; }
  .pv-grid td {
    width: 2rem; height: 2rem; text-align: center; vertical-align: middle;
    border: 1px solid #c9c9cf; font-size: .95rem;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  }
  .pv-grid td:nth-child({$variant->boxWidth}n+1) { border-left: 2.5px solid #1c1c1e; }
  .pv-grid tr:nth-child({$variant->boxHeight}n+1) td { border-top: 2.5px solid #1c1c1e; }
  .pv-grid td.pv-given { font-weight: 600; color: #1c1c1e; }
  .pv-grid td.pv-filled { color: #8a4b2a; background: #fffdf9; }

  .pv-wrap { overflow-x: auto; }
  .pv-steps { border-collapse: collapse; width: 100%; background: #fff;
              border: 1px solid #c9c9cf; border-radius: 8px; font-size: .82rem; }
  .pv-steps th { text-align: left; font-size: .7rem; text-transform: uppercase;
                 letter-spacing: .07em; color: #6b6b70; font-weight: 600;
                 padding: .5rem .7rem; border-bottom: 1px solid #c9c9cf; }
  .pv-steps td { padding: .34rem .7rem; border-bottom: 1px solid #efeff1; vertical-align: top; }
  .pv-steps tr:last-child td { border-bottom: none; }
  .pv-steps .pv-num { text-align: right; color: #96969c; width: 3rem;
                      font-variant-numeric: tabular-nums; }
  .pv-steps .pv-cell { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; white-space: nowrap; }
  .pv-steps .pv-sym { text-align: center; width: 4rem;
                      font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
  .pv-steps .pv-why { color: #6b6b70; }
  .pv-steps .pv-tier { color: #96969c; font-size: .72rem; }
  .pv-steps tr.pv-place { background: #fffdf9; }
  .pv-steps tr.pv-elim td { color: #6b6b70; }
  .pv-hide-elims tr.pv-elim { display: none; }
CSS;
    }

    /** The answer key: the completed grid, givens bold, filled cells tinted. */
    public static function answerKey(Puzzle $p): string
    {
        $n    = $p->size();
        $html = '<table class="pv-grid">';
        for ($r = 0; $r < $n; $r++) {
            $html .= '<tr>';
            for ($c = 0; $c < $n; $c++) {
                $cls = $p->isGiven($r, $c) ? 'pv-given' : 'pv-filled';
                $html .= '<td class="' . $cls . '">' . self::h($p->solutionSymbol($r, $c)) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</table>';
    }

    /** The inference walkthrough: one row per deduction, in derivation order. */
    public static function steps(array $steps, SudokuEngine $engine): string
    {
        $labels = SudokuEngine::techniqueLabels();

        $html = '<table class="pv-steps"><thead><tr>'
              . '<th class="pv-num">#</th><th>Cell</th><th class="pv-sym">Symbol</th>'
              . '<th>Technique</th><th>Why</th>'
              . '</tr></thead><tbody>';

        $i = 0;
        foreach ($steps as $st) {
            $meta = $labels[$st['key']] ?? ['label' => $st['key'], 'tier' => 0];
            $tech = self::h($meta['label']) . ' <span class="pv-tier">T' . self::h($meta['tier']) . '</span>';

            if (($st['type'] ?? '') === 'place') {
                $i++;
                $html .= '<tr class="pv-place">'
                       . '<td class="pv-num">' . $i . '</td>'
                       . '<td class="pv-cell">' . self::h($engine->cellName((int) $st['cell'])) . '</td>'
                       . '<td class="pv-sym"><b>' . self::h($engine->symbolOf((int) $st['value'])) . '</b></td>'
                       . '<td>' . $tech . '</td>'
                       . '<td class="pv-why">' . self::h($st['detail'] ?? '') . '</td>'
                       . '</tr>';
                continue;
            }

            $parts = [];
            foreach (array_slice((array) ($st['elims'] ?? []), 0, 8) as $el) {
                $parts[] = self::h($engine->cellName((int) $el['cell']))
                    . '&minus;' . self::h($engine->symbolsOf((int) $el['mask']));
            }
            $extra   = count((array) ($st['elims'] ?? [])) - 8;
            $summary = implode(', ', $parts) . ($extra > 0 ? ', <i>+' . $extra . ' more</i>' : '');

            $html .= '<tr class="pv-elim">'
                   . '<td class="pv-num"></td>'
                   . '<td class="pv-cell">' . $summary . '</td>'
                   . '<td class="pv-sym">&ndash;</td>'
                   . '<td>' . $tech . '</td>'
                   . '<td class="pv-why">' . self::h($st['detail'] ?? '') . '</td>'
                   . '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /** @return array{places:int,elims:int} */
    public static function tally(array $steps): array
    {
        $places = $elims = 0;
        foreach ($steps as $st) {
            if (($st['type'] ?? '') === 'place') {
                $places++;
            } else {
                $elims++;
            }
        }
        return ['places' => $places, 'elims' => $elims];
    }
}
