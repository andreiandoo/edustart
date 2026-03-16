<?php
// admin/import.php

function edu_render_import_interface() {
    global $wpdb;

    $upload_dir = wp_upload_dir();
    $import_dir = $upload_dir['basedir'] . '/edu-import';

    echo '<h2 class="mb-2 text-xl font-bold">Importă școli din fișier CSV</h2>';
    echo '<form method="post" enctype="multipart/form-data" class="mb-4" id="edu-import-form">';
    echo wp_nonce_field('edu_import_csv', 'edu_csv_nonce', true, false);
    echo '<input type="file" name="edu_csv_file" accept=".csv" required class="p-2 mr-2 border">';
    echo '<label style="margin-left:12px;"><input type="checkbox" name="edu_overwrite" value="1"> Suprascrie școlile existente (după cod SIIIR)</label>';
    echo '<br><br>';
    echo '<button type="submit" name="edu_import_submit" class="button button-primary">Importă</button>';
    echo '</form>';

    echo '<div id="edu-import-progress" style="display:none; margin-top:15px;">';
    echo '<div style="background:#f0f0f0; border-radius:4px; overflow:hidden; height:24px; width:100%; max-width:600px;">';
    echo '<div id="edu-progress-bar" style="background:#0073aa; height:100%; width:0%; transition:width 0.3s; text-align:center; color:#fff; line-height:24px; font-size:13px;">0%</div>';
    echo '</div>';
    echo '<p id="edu-import-status" style="margin-top:8px;">Se pregătește importul...</p>';
    echo '</div>';

    // Pasul 1: upload CSV pe server
    if (isset($_POST['edu_import_submit']) && wp_verify_nonce($_POST['edu_csv_nonce'], 'edu_import_csv')) {

        if (empty($_FILES['edu_csv_file']['tmp_name']) || !is_uploaded_file($_FILES['edu_csv_file']['tmp_name'])) {
            echo '<div class="notice notice-error"><p>Fișier invalid sau neîncărcat corect.</p></div>';
            return;
        }

        if (!file_exists($import_dir)) {
            wp_mkdir_p($import_dir);
        }

        $dest = $import_dir . '/schools-import.csv';
        move_uploaded_file($_FILES['edu_csv_file']['tmp_name'], $dest);

        $overwrite = !empty($_POST['edu_overwrite']) ? 1 : 0;

        // Numără rânduri
        $total_lines = 0;
        if (($h = fopen($dest, 'r')) !== false) {
            while (fgets($h) !== false) $total_lines++;
            fclose($h);
        }
        $total_lines = max(0, $total_lines - 1); // minus header

        echo '<script>
        (function(){
            var total = ' . $total_lines . ';
            var overwrite = ' . $overwrite . ';
            var offset = 0;
            var batchSize = 500;
            var inserted = 0, updated = 0, skipped = 0, errors = 0;
            var progressEl = document.getElementById("edu-import-progress");
            var barEl = document.getElementById("edu-progress-bar");
            var statusEl = document.getElementById("edu-import-status");
            var formEl = document.getElementById("edu-import-form");

            progressEl.style.display = "block";
            formEl.style.display = "none";

            function processBatch() {
                var xhr = new XMLHttpRequest();
                xhr.open("POST", ajaxurl);
                xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        if (xhr.status === 200) {
                            try {
                                var resp = JSON.parse(xhr.responseText);
                                if (resp.success) {
                                    var d = resp.data;
                                    inserted += d.inserted;
                                    updated += d.updated;
                                    skipped += d.skipped;
                                    errors += d.errors;
                                    offset = d.next_offset;

                                    var processed = Math.min(offset, total);
                                    var pct = total > 0 ? Math.round((processed / total) * 100) : 100;
                                    barEl.style.width = pct + "%";
                                    barEl.textContent = pct + "%";
                                    statusEl.textContent = "Procesate " + processed + " / " + total + " rânduri (" + inserted + " inserate, " + updated + " actualizate, " + skipped + " omise, " + errors + " erori)";

                                    if (d.done) {
                                        barEl.style.width = "100%";
                                        barEl.textContent = "100%";
                                        barEl.style.background = "#46b450";
                                        statusEl.innerHTML = "<strong>Import finalizat!</strong> " + inserted + " inserate, " + updated + " actualizate, " + skipped + " omise (existente), " + errors + " erori.";
                                    } else {
                                        processBatch();
                                    }
                                } else {
                                    statusEl.textContent = "Eroare: " + (resp.data || "necunoscută");
                                    barEl.style.background = "#dc3232";
                                }
                            } catch(e) {
                                statusEl.textContent = "Eroare la parsarea răspunsului.";
                                barEl.style.background = "#dc3232";
                            }
                        } else {
                            statusEl.textContent = "Eroare HTTP: " + xhr.status;
                            barEl.style.background = "#dc3232";
                        }
                    }
                };
                xhr.send("action=edu_import_schools_batch&offset=" + offset + "&batch_size=" + batchSize + "&overwrite=" + overwrite + "&_ajax_nonce=' . wp_create_nonce('edu_import_batch') . '");
            }

            processBatch();
        })();
        </script>';
    }
}

// AJAX batch import handler
add_action('wp_ajax_edu_import_schools_batch', 'edu_import_schools_batch');
function edu_import_schools_batch() {
    check_ajax_referer('edu_import_batch');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Fără permisiuni.');
    }

    global $wpdb;

    $upload_dir = wp_upload_dir();
    $file = $upload_dir['basedir'] . '/edu-import/schools-import.csv';

    if (!file_exists($file)) {
        wp_send_json_error('Fișierul CSV nu a fost găsit. Reîncarcă fișierul.');
    }

    $offset     = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 500;
    $overwrite  = !empty($_POST['overwrite']);

    $handle = fopen($file, 'r');
    if (!$handle) {
        wp_send_json_error('Nu pot deschide fișierul CSV.');
    }

    // Auto-detect delimiter
    $first_line = fgets($handle);
    rewind($handle);
    $delimiter = (strpos($first_line, "\t") !== false) ? "\t" : ",";

    // Skip header + already processed rows
    $current_row = 0;
    while (($data = fgetcsv($handle, 10000, $delimiter)) !== false) {
        $current_row++;
        if ($current_row === 1) continue; // header
        if ($current_row <= $offset + 1) continue; // +1 for header
        break;
    }

    // If we already exhausted the file skipping
    if ($data === false) {
        fclose($handle);
        @unlink($file);
        wp_send_json_success(['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'next_offset' => $offset, 'done' => true]);
    }

    $ok = 0;
    $skip = 0;
    $updated = 0;
    $fail = 0;
    $processed = 0;

    // Process current row + rest of batch
    do {
        if (!is_array($data) || count($data) < 6) {
            $fail++;
            $processed++;
            if ($processed >= $batch_size) break;
            continue;
        }

        // CSV columns (18):
        // 0: judet, 1: oras, 2: sat, 3: cod, 4: mediu, 5: name, 6: short,
        // 7: location, 8: superior_location, 9: regiune_tfr, 10: statut,
        // 11: medie_irse, 12: scor_irse, 13: strategic,
        // 14: index_vulnerabilitate_tfr, 15: numar_elevi_siiir, 16: tip, 17: first_year_tfr

        $judet     = isset($data[0]) ? sanitize_text_field($data[0]) : '';
        $oras      = isset($data[1]) ? sanitize_text_field($data[1]) : '';
        $sat       = isset($data[2]) ? sanitize_text_field($data[2]) : '';
        $cod       = isset($data[3]) ? preg_replace('/[^0-9]/', '', $data[3]) : '0';
        $mediu_csv = isset($data[4]) ? strtolower(trim($data[4])) : '';
        $mediu     = in_array($mediu_csv, ['urban', 'rural'], true) ? $mediu_csv : null;
        $name      = isset($data[5]) ? sanitize_text_field($data[5]) : '';
        $short     = isset($data[6]) ? sanitize_text_field($data[6]) : '';
        $loc       = isset($data[7]) ? sanitize_text_field($data[7]) : '';
        $superior  = isset($data[8]) ? sanitize_text_field($data[8]) : '';
        $regiune   = isset($data[9]) && in_array($data[9], ['RMD', 'RCV', 'SUD'], true) ? $data[9] : 'RMD';
        $statut    = isset($data[10]) ? sanitize_text_field($data[10]) : '';
        $medie     = isset($data[11]) && $data[11] !== '' ? floatval($data[11]) : null;
        $scor      = isset($data[12]) && $data[12] !== '' ? floatval($data[12]) : null;
        $strategic = isset($data[13]) && intval($data[13]) === 1 ? 1 : 0;

        $index_vulnerabilitate_tfr = isset($data[14]) && $data[14] !== '' ? sanitize_text_field($data[14]) : null;
        $numar_elevi_siiir         = isset($data[15]) && $data[15] !== '' ? intval($data[15])   : null;
        $tip                       = isset($data[16]) && $data[16] !== '' ? sanitize_text_field($data[16]) : null;
        $first_year_tfr            = isset($data[17]) && $data[17] !== '' ? sanitize_text_field($data[17]) : null;

        if ($judet === '' || $oras === '' || $cod === '0' || $cod === '' || $name === '') {
            $fail++;
            $processed++;
            if ($processed >= $batch_size) break;
            continue;
        }

        // County
        $county_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}edu_counties WHERE name = %s", $judet
        ));
        if (!$county_id) {
            $wpdb->insert("{$wpdb->prefix}edu_counties", ['name' => $judet]);
            $county_id = (int)$wpdb->insert_id;
        }

        // City
        $city_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}edu_cities WHERE name = %s AND county_id = %d AND (parent_city_id IS NULL OR parent_city_id = 0)",
            $oras, $county_id
        ));
        if (!$city_id) {
            $wpdb->insert("{$wpdb->prefix}edu_cities", [
                'name' => $oras, 'county_id' => $county_id, 'parent_city_id' => null
            ]);
            $city_id = (int)$wpdb->insert_id;
        }

        // Village
        $village_id = null;
        if ($sat !== '') {
            $village_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}edu_cities WHERE name = %s AND parent_city_id = %d",
                $sat, $city_id
            ));
            if (!$village_id) {
                $wpdb->insert("{$wpdb->prefix}edu_cities", [
                    'name' => $sat, 'county_id' => $county_id, 'parent_city_id' => $city_id
                ]);
                $village_id = (int)$wpdb->insert_id;
            }
        }

        // Check existing
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}edu_schools WHERE cod = %s", $cod
        ));

        $school_data = [
            'city_id'                   => $city_id,
            'village_id'                => $village_id,
            'cod'                       => $cod,
            'name'                      => $name,
            'short_name'                => $short,
            'location'                  => $loc,
            'superior_location'         => $superior,
            'county'                    => $judet,
            'regiune_tfr'               => $regiune,
            'statut'                    => $statut,
            'medie_irse'                => $medie,
            'scor_irse'                 => $scor,
            'strategic'                 => $strategic,
            'index_vulnerabilitate_tfr' => $index_vulnerabilitate_tfr,
            'numar_elevi_siiir'         => $numar_elevi_siiir,
            'tip'                       => $tip,
            'first_year_tfr'            => $first_year_tfr,
            'mediu'                     => $mediu,
        ];
        $formats = ['%d','%d','%s','%s','%s','%s','%s','%s','%s',
                    '%s','%f','%f','%d','%s','%d','%s','%s','%s'];

        if ($existing_id) {
            if ($overwrite) {
                $result = $wpdb->update("{$wpdb->prefix}edu_schools", $school_data, ['id' => $existing_id], $formats, ['%d']);
                if ($result !== false) $updated++; else $fail++;
            } else {
                $skip++;
            }
        } else {
            $inserted = $wpdb->insert("{$wpdb->prefix}edu_schools", $school_data, $formats);
            if ($inserted !== false) $ok++; else $fail++;
        }

        $processed++;
        if ($processed >= $batch_size) break;

    } while (($data = fgetcsv($handle, 10000, $delimiter)) !== false);

    $done = ($data === false && $processed < $batch_size);
    $next_offset = $offset + $processed;

    fclose($handle);

    if ($done) {
        @unlink($file);
    }

    wp_send_json_success([
        'inserted'    => $ok,
        'updated'     => $updated,
        'skipped'     => $skip,
        'errors'      => $fail,
        'next_offset' => $next_offset,
        'done'        => $done,
    ]);
}
