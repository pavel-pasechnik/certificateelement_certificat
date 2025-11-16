<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace certificateelement_certificat;

use stored_file;
use tool_certificate\element_helper;

/**
 * Certificate element that renders imported PDF backgrounds.
 *
 * @package   certificateelement_certificat
 * @copyright 2025 Pavel Pasechnik
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class element extends \tool_certificate\element {

    /**
     * Persist configuration.
     *
     * @param \stdClass $data
     */
    public function save_form_data(\stdClass $data) {
        $data->data = $this->build_configuration_payload($data);
        parent::save_form_data($data);
    }

    /**
     * Draw the element content onto the generated PDF.
     *
     * @param \pdf $pdf
     * @param bool $preview
     * @param \stdClass $user
     * @param \stdClass|null $issue
     */
    public function render($pdf, $preview, $user, $issue) {
        if ($preview || empty($issue) || empty($issue->id)) {
            $file = $this->resolve_preview_file();
        } else {
            $file = $this->resolve_issue_file($issue);
        }

        if (!$file instanceof stored_file) {
            $identifier = ($preview || empty($issue) || empty($issue->id)) ? 'message:preview' : 'message:missing';
            element_helper::render_content($pdf, $this, get_string($identifier, 'certificateelement_certificat'));
            return;
        }

        [$width, $height] = $this->get_page_dimensions($pdf);
        element_helper::render_image($pdf, $this, $file, [], $width, $height);
    }

    /**
     * Render HTML preview in the drag-and-drop designer.
     *
     * @return string
     */
    public function render_html() {
        $hasimports = true;
        $file = $this->resolve_preview_file($hasimports);

        if (!$file && !$hasimports) {
            $message = get_string('message:noimports', 'certificateelement_certificat');
            return element_helper::render_html_content($this, $message);
        }

        if ($file instanceof stored_file) {
            [$width, $height] = $this->get_page_dimensions();
            $url = \moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename()
            );
            return element_helper::render_image_html($url, $file->get_imageinfo(), $width, $height, $file->get_filename());
        }

        $message = get_string('message:preview', 'certificateelement_certificat');
        return element_helper::render_html_content($this, $message);
    }

    /**
     * Resolve the stored file that contains the imported background.
     *
     * @param \stdClass|null $issue
     * @return stored_file|null
     */
    protected function resolve_issue_file(?\stdClass $issue): ?stored_file {
        if (empty($issue) || empty($issue->data)) {
            return null;
        }

        $payload = $this->get_issue_import_payload($issue);
        if (!$payload) {
            return null;
        }

        $file = $this->get_file_by_id((int)$payload['backgroundfileid']);
        if (!$file) {
            return null;
        }

        $contextid = (int)$payload['contextid'];
        if ($contextid && (int)$file->get_contextid() !== $contextid) {
            return null;
        }

        if ($file->get_component() !== 'local_certificateimport' || $file->get_filearea() !== 'backgrounds') {
            return null;
        }

        return $file;
    }

    /**
     * Returns importer payload stored within the issue record.
     *
     * @param \stdClass $issue
     * @return array|null
     */
    protected function get_issue_import_payload(\stdClass $issue): ?array {
        $data = $issue->data;
        if (is_string($data)) {
            $decoded = json_decode($data, true);
        } else if (is_array($data)) {
            $decoded = $data;
        } else if (is_object($data)) {
            $decoded = (array)$data;
        } else {
            $decoded = [];
        }

        if (empty($decoded['local_certificateimport']) || !is_array($decoded['local_certificateimport'])) {
            return null;
        }

        $payload = $decoded['local_certificateimport'];
        if (empty($payload['backgroundfileid'])) {
            return null;
        }

        $payload['backgroundfileid'] = (int)$payload['backgroundfileid'];
        $payload['contextid'] = isset($payload['contextid']) ? (int)$payload['contextid'] : 0;

        return $payload;
    }

    /**
     * Builds the JSON payload stored in the element data column.
     *
     * @param \stdClass $data
     * @return string
     */
    protected function build_configuration_payload(\stdClass $data): string {
        return json_encode(new \stdClass());
    }

    /**
     * This element always occupies the full page background, so dragging is disabled.
     *
     * @return bool
     */
    public function is_draggable(): bool {
        return false;
    }

    /**
     * Imported backgrounds always start from the top-left corner.
     *
     * @return float
     */
    public function get_posx() {
        return 0.0;
    }

    /**
     * Imported backgrounds always start from the top-left corner.
     *
     * @return float
     */
    public function get_posy() {
        return 0.0;
    }

    /**
     * Resolve the current page dimensions either from TCPDF or template metadata.
     *
     * @param \pdf|null $pdf
     * @return array{0:float,1:float}
     */
    protected function get_page_dimensions($pdf = null): array {
        $width = 0.0;
        $height = 0.0;

        if ($pdf) {
            if (method_exists($pdf, 'getPageWidth')) {
                $width = (float)$pdf->getPageWidth();
            }
            if (method_exists($pdf, 'getPageHeight')) {
                $height = (float)$pdf->getPageHeight();
            }
        }

        if ((!$width || !$height) && $this->get_page()) {
            $page = $this->get_page()->to_record();
            $width = $width ?: (float)($page->width ?? 0);
            $height = $height ?: (float)($page->height ?? 0);
        }

        return [$width, $height];
    }

    /**
     * Retrieves the most recently imported background file for preview purposes.
     *
     * @return stored_file|null
     */
    protected function resolve_preview_file(?bool &$hasimports = null): ?stored_file {
        global $DB;

        $hasimports = true;

        try {
            $records = $DB->get_records_select(
                'local_certimp_items',
                'backgroundfileid IS NOT NULL AND backgroundfileid <> 0',
                [],
                'timeprocessed DESC, timecreated DESC, id DESC',
                'backgroundfileid',
                0,
                1
            );
        } catch (\dml_exception $exception) {
            debugging('local_certificateimport data not available: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            $hasimports = false;
            return null;
        }

        if (!$records) {
            $hasimports = false;
            return null;
        }

        $record = reset($records);
        return $this->get_file_by_id((int)$record->backgroundfileid);
    }

    /**
     * Helper to fetch a stored file by its ID.
     *
     * @param int $fileid
     * @return stored_file|null
     */
    protected function get_file_by_id(int $fileid): ?stored_file {
        if ($fileid <= 0) {
            return null;
        }

        $fs = get_file_storage();
        $file = $fs->get_file_by_id($fileid);

        return $file instanceof stored_file ? $file : null;
    }
}
