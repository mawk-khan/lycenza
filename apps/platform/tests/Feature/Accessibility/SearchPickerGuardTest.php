<?php

namespace Tests\Feature\Accessibility;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * UAT-C4 (UAT-A11Y-01, ADR 0075 section 17): search-and-pick results are
 * operated by keyboard and screen reader through the one shared
 * `Components/Form/SearchCombobox.vue` (combobox + listbox, Arrow/Enter/
 * Escape, live announcements). A results list built from click-only `<li>`
 * elements is how 29 pickers on 15 pages locked keyboard users out; this
 * guard keeps it from coming back, and every picker must be named.
 */
class SearchPickerGuardTest extends TestCase
{
    /** @return array<string, string> relative path => source */
    private function vueFiles(): array
    {
        $files = [];
        foreach ((new Finder)->files()->in(resource_path('js'))->name('*.vue') as $file) {
            $files[$file->getRelativePathname()] = $file->getContents();
        }

        return $files;
    }

    #[Test]
    public function no_list_item_is_a_click_only_control(): void
    {
        $offenders = [];
        foreach ($this->vueFiles() as $path => $source) {
            if ($path === 'Components/Form/SearchCombobox.vue') {
                continue; // the one option element; its keyboard lives on the combobox input
            }
            preg_match_all('/<li\b[^>]*>/s', $source, $tags);
            foreach ($tags[0] as $tag) {
                if (preg_match('/\s(@click|v-on:click)=/', $tag) === 1) {
                    $offenders[] = $path.': '.preg_replace('/\s+/', ' ', $tag);
                }
            }
        }

        $this->assertSame([], $offenders, 'Use SearchCombobox (or a real <button>) for a clickable result.');
    }

    #[Test]
    public function every_search_combobox_has_an_id_and_an_accessible_name(): void
    {
        $count = 0;
        foreach ($this->vueFiles() as $path => $source) {
            preg_match_all('/<SearchCombobox\b(.*?)>/s', $source, $uses);
            foreach ($uses[1] as $attributes) {
                $count++;
                $this->assertMatchesRegularExpression('/\sid="([^"]+)"/', $attributes, "{$path}: a SearchCombobox needs an id");
                preg_match('/\sid="([^"]+)"/', $attributes, $id);
                $named = str_contains($attributes, 'aria-label=') || preg_match('/<label[^>]*\sfor="'.preg_quote($id[1], '/').'"/', $source) === 1;
                $this->assertTrue($named, "{$path}: the SearchCombobox #{$id[1]} needs a <label for> or an aria-label");
            }
        }

        $this->assertGreaterThanOrEqual(29, $count, 'Every converted picker still uses the shared component.');
    }
}
