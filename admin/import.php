<?php
// admin/import.php

function edu_render_import_interface() {
    global $wpdb;

    echo '<h2 class="mb-2 text-xl font-bold">Importă școli din fișier CSV</h2>';
    echo '<form method="post" enctype="multipart/form-data" class="mb-4">';
    echo wp_nonce_field('edu_import_csv', 'edu_csv_nonce', true, false);
    echo '<input type="file" name="edu_csv_file" accept=".csv" required class="p-2 mr-2 border">';
    echo '<button type="submit" name="edu_import_submit" class="button button-primary">Importă</button>';
    echo '</form>';

    if (isset($_POST['edu_import_submit']) && wp_verify_nonce($_POST['edu_csv_nonce'], 'edu_import_csv')) {

        if (empty($_FILES['edu_csv_file']['tmp_name']) || !is_uploaded_file($_FILES['edu_csv_file']['tmp_name'])) {
            echo '<div class="notice notice-error"><p>Fișier invalid sau neîncărcat corect.</p></div>';
            return;
        }

        $file = $_FILES['edu_csv_file']['tmp_name'];

        if (($handle = fopen($file, 'r')) !== false) {
            $row = 0;
            $ok = 0;
            $fail = 0;

            while (($data = fgetcsv($handle, 10000, ",")) !== false) {
                // sărim antetul
                if (++$row === 1) continue;

                // Linii goale / invalide: ocolim
                if (!is_array($data) || count($data) < 6) { // minim până la $name (index 5)
                    $fail++;
                    continue;
                }

                // CSV așteptat (18 coloane):
                // 0: judet, 1: oras, 2: sat, 3: cod, 4: mediu, 5: name, 6: short,
                // 7: location, 8: superior_location, 9: regiune_tfr, 10: statut,
                // 11: medie_irse, 12: scor_irse, 13: strategic,
                // 14: index_vulnerabilitate_tfr, 15: numar_elevi_siiir, 16: tip, 17: first_year_tfr

                $judet     = isset($data[0]) ? sanitize_text_field($data[0]) : '';
                $oras      = isset($data[1]) ? sanitize_text_field($data[1]) : '';
                $sat       = isset($data[2]) ? sanitize_text_field($data[2]) : '';
                $cod       = isset($data[3]) ? intval($data[3]) : 0;
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

                // Validări minime
                if ($judet === '' || $oras === '' || $cod === 0 || $name === '') {
                    $fail++;
                    continue;
                }

                // Asigură existența județului
                $county_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}edu_counties WHERE name = %s",
                    $judet
                ));
                if (!$county_id) {
                    // dacă ai schema cu id autoincrement pe counties, merge direct
                    $wpdb->insert("{$wpdb->prefix}edu_counties", ['name' => $judet]);
                    $county_id = (int)$wpdb->insert_id;
                }

                // Asigură existența orașului (parent_city_id IS NULL)
                $city_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}edu_cities 
                     WHERE name = %s AND county_id = %d AND (parent_city_id IS NULL OR parent_city_id = 0)",
                    $oras, $county_id
                ));
                if (!$city_id) {
                    $wpdb->insert("{$wpdb->prefix}edu_cities", [
                        'name'           => $oras,
                        'county_id'      => $county_id,
                        'parent_city_id' => null
                    ]);
                    $city_id = (int)$wpdb->insert_id;
                }

                // Asigură existența satului/comunei (dacă e cazul)
                $village_id = null;
                if ($sat !== '') {
                    $village_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}edu_cities WHERE name = %s AND parent_city_id = %d",
                        $sat, $city_id
                    ));
                    if (!$village_id) {
                        $wpdb->insert("{$wpdb->prefix}edu_cities", [
                            'name'           => $sat,
                            'county_id'      => $county_id,
                            'parent_city_id' => $city_id
                        ]);
                        $village_id = (int)$wpdb->insert_id;
                    }
                }

                // Inserare școală
                $inserted = $wpdb->insert("{$wpdb->prefix}edu_schools", [
                    'city_id'                 => $city_id,
                    'village_id'              => $village_id,
                    'cod'                     => $cod,
                    'name'                    => $name,
                    'short_name'              => $short,
                    'location'                => $loc,
                    'superior_location'       => $superior,
                    'county'                  => $judet,
                    'regiune_tfr'             => $regiune,
                    'statut'                  => $statut,
                    'medie_irse'              => $medie,
                    'scor_irse'               => $scor,
                    'strategic'               => $strategic,
                    // NOI:
                    'index_vulnerabilitate_tfr' => $index_vulnerabilitate_tfr,
                    'numar_elevi_siiir'         => $numar_elevi_siiir,
                    'tip'                       => $tip,
                    'first_year_tfr'            => $first_year_tfr,
                    'mediu'                     => $mediu,
                ],
                [
                    '%d','%d','%d','%s','%s','%s','%s','%s','%s',
                    '%s','%f','%f','%d','%s','%d','%s','%s','%s'
                ]);

                if ($inserted !== false) $ok++; else $fail++;
            }

            fclose($handle);
            echo '<div class="notice notice-success"><p>Import finalizat: ' . intval($ok) . ' rânduri OK, ' . intval($fail) . ' rânduri cu erori.</p></div>';
        }
    }
}
