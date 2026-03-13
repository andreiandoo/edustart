<?php
// admin/schools.php

function edu_render_school_manager() {
    global $wpdb;

    $counties = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}edu_counties ORDER BY name");

    // Ștergere școală
    if (isset($_GET['delete_school']) && is_numeric($_GET['delete_school'])) {
        $wpdb->delete("{$wpdb->prefix}edu_schools", ['id' => intval($_GET['delete_school'])]);
        echo '<div class="notice notice-success"><p>Școala a fost ștearsă.</p></div>';
    }

    // Preluare date pentru editare
    $edit_school = null;
    $edit_school_city_row = null; // pentru county_id și parent_city_id ale orașului selectat
    $selected_county_id = null;

    if (isset($_GET['edit_school']) && is_numeric($_GET['edit_school'])) {
        $edit_school = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}edu_schools WHERE id = %d", $_GET['edit_school']));
        if ($edit_school && !empty($edit_school->city_id)) {
            $edit_school_city_row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, county_id, parent_city_id FROM {$wpdb->prefix}edu_cities WHERE id = %d",
                (int)$edit_school->city_id
            ));
            if ($edit_school_city_row) {
                $selected_county_id = (int)$edit_school_city_row->county_id;
            }
        }
    }

    // Salvare (inserare sau actualizare)
    if (
        isset($_POST['action']) &&
        $_POST['action'] === 'adauga_scoala' &&
        !empty($_POST['city_id']) &&
        !empty($_POST['cod']) &&
        !empty($_POST['name'])
    ) {
        $city_id    = intval($_POST['city_id']);
        $village_id = !empty($_POST['village_id']) ? intval($_POST['village_id']) : null;

        // mediu: din formular (dropdown) cu fallback auto-calc
        $mediu_post = strtolower($_POST['mediu'] ?? '');
        if (in_array($mediu_post, ['urban', 'rural'], true)) {
            $mediu = $mediu_post;
        } else {
            $mediu = 'urban';
            if (!empty($village_id)) {
                $mediu = 'rural';
            } else {
                $city_parent = $wpdb->get_var($wpdb->prepare(
                    "SELECT parent_city_id FROM {$wpdb->prefix}edu_cities WHERE id=%d",
                    $city_id
                ));
                if (!is_null($city_parent) && (int)$city_parent > 0) {
                    $mediu = 'rural';
                }
            }
        }

        $data = [
            'city_id'           => $city_id,
            'village_id'        => $village_id,
            'cod'               => intval($_POST['cod']),
            'name'              => sanitize_text_field($_POST['name']),
            'short_name'        => sanitize_text_field($_POST['short_name']),
            'regiune_tfr'       => in_array($_POST['regiune_tfr'], ['RMD', 'RCV', 'SUD']) ? $_POST['regiune_tfr'] : 'RMD',
            'statut'            => in_array(($_POST['statut'] ?? ''), ['Cu personalitate juridică', 'Arondată', ''], true) ? $_POST['statut'] : '',
            'tip'               => in_array(($_POST['tip'] ?? ''), ['Public', 'Privat'], true) ? $_POST['tip'] : null,
            'first_year_tfr'    => (($_POST['first_year_tfr'] ?? '') !== '' ? sanitize_text_field($_POST['first_year_tfr']) : null),
            'medie_irse'        => ($_POST['medie_irse'] !== '' ? floatval($_POST['medie_irse']) : null),
            'scor_irse'         => ($_POST['scor_irse']  !== '' ? floatval($_POST['scor_irse'])  : null),
            'strategic'         => isset($_POST['strategic']) ? 1 : 0,

            // NOI
            'index_vulnerabilitate_tfr' => (isset($_POST['index_vulnerabilitate_tfr']) && $_POST['index_vulnerabilitate_tfr'] !== '' ? sanitize_text_field($_POST['index_vulnerabilitate_tfr']) : null),
            'numar_elevi_siiir'         => (isset($_POST['numar_elevi_siiir']) && $_POST['numar_elevi_siiir'] !== '' ? intval($_POST['numar_elevi_siiir']) : null),
            'mediu'                     => $mediu,
        ];

        if (!empty($_POST['school_id'])) {
            $wpdb->update("{$wpdb->prefix}edu_schools", $data, ['id' => intval($_POST['school_id'])]);
            echo '<div class="notice notice-success"><p>Școala a fost actualizată.</p></div>';
        } else {
            $wpdb->insert("{$wpdb->prefix}edu_schools", $data);
            echo '<div class="notice notice-success"><p>Școală adăugată.</p></div>';
        }

        // pentru UX, actualizăm variabilele de edit dacă tocmai am salvat și rămânem în pagină
        if (!empty($_POST['school_id'])) {
            $edit_school = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}edu_schools WHERE id = %d", $_POST['school_id']));
            if ($edit_school && !empty($edit_school->city_id)) {
                $edit_school_city_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, county_id, parent_city_id FROM {$wpdb->prefix}edu_cities WHERE id = %d",
                    (int)$edit_school->city_id
                ));
                if ($edit_school_city_row) {
                    $selected_county_id = (int)$edit_school_city_row->county_id;
                }
            }
        }
    }

    echo '<h2 class="mb-2 text-xl font-semibold">' . ($edit_school ? 'Editează școală' : 'Adaugă o școală') . '</h2>';
    echo '<form method="post" class="mb-6" id="edu-school-form">';
        echo '<input type="hidden" name="action" value="adauga_scoala">';
        if ($edit_school) echo '<input type="hidden" name="school_id" value="' . esc_attr($edit_school->id) . '">';

        // valori implicite pentru preselectare (edit)
        $prefill_city_id    = $edit_school ? (int)$edit_school->city_id : 0;
        $prefill_village_id = $edit_school && !empty($edit_school->village_id) ? (int)$edit_school->village_id : 0;
        $prefill_county_id  = $selected_county_id ?: 0;

        echo '<input type="hidden" id="prefill_city_id" value="' . esc_attr($prefill_city_id) . '">';
        echo '<input type="hidden" id="prefill_village_id" value="' . esc_attr($prefill_village_id) . '">';
        echo '<input type="hidden" id="prefill_county_id" value="' . esc_attr($prefill_county_id) . '">';

        // nonce pentru AJAX locații (conform ajax/load-cities.php)
        $loc_nonce = wp_create_nonce('edu_location_nonce');
        echo '<input type="hidden" id="edu_location_nonce" value="' . esc_attr($loc_nonce) . '">';

        echo '<div class="grid grid-cols-3 gap-4">';

            // ===== COL 1
            echo '<div class="flex flex-col gap-y-4">';

                // Județ
                echo '<div class="flex flex-col">';
                    echo '<label class="block mb-1">Județ</label>';
                    echo '<select name="county_id" id="edu_county" class="w-full p-2 mb-2 border rounded" required>';
                    echo '<option value="">Selectează județ</option>';
                    foreach ($counties as $county) {
                        $sel = ($prefill_county_id && (int)$county->id === (int)$prefill_county_id) ? ' selected' : '';
                        echo "<option value='{$county->id}'{$sel}>{$county->name}</option>";
                    }
                    echo '</select>';
                echo '</div>';

                // Oraș
                echo '<div class="flex flex-col">';
                    echo '<label class="block mb-1">Oraș</label>';
                    echo '<select name="city_id" id="edu_city" class="w-full p-2 mb-2 border rounded" required>';
                    echo '<option value="">Selectează oraș</option>';
                    echo '</select>';
                echo '</div>';

                // Comună / Sat
                echo '<div class="flex flex-col">';
                    echo '<label class="block mb-1">Comună/Sat (opțional)</label>';
                    echo '<select name="village_id" id="edu_village" class="w-full p-2 mb-2 border rounded">';
                    echo '<option value="">Selectează comună/sat (dacă este cazul)</option>';
                    echo '</select>';
                echo '</div>';

                // Cod SIIIR
                echo '<div class="flex flex-col">';
                    echo '<label class="block mb-1">Cod SIIIR</label>';
                    echo '<input type="number" name="cod" class="w-full p-2 mb-2 border rounded" required value="' . esc_attr($edit_school->cod ?? '') . '">';
                echo '</div>';

            echo '</div>'; // end col 1

            // ===== COL 2
            echo '<div class="">';

                echo '<div class="flex flex-col">';
                    echo '<label class="block mb-1">Nume școală</label>';
                    echo '<input type="text" name="name" class="w-full p-2 mb-2 border rounded" required value="' . esc_attr($edit_school->name ?? '') . '">';
                echo '</div>';

                echo '<div class="flex flex-col">';
                    echo '<label class="block mb-1">Denumire scurtă</label>';
                    echo '<input type="text" name="short_name" class="w-full p-2 mb-2 border rounded" value="' . esc_attr($edit_school->short_name ?? '') . '">';
                echo '</div>';

                echo '<div class="flex flex-col">';
                    echo '<label class="block mb-1">Regiune TFR</label>';
                    echo '<select name="regiune_tfr" class="w-full p-2 mb-2 border rounded">';
                    foreach (["RMD", "RCV", "SUD"] as $reg) {
                        $selected = ($edit_school && $edit_school->regiune_tfr === $reg) ? 'selected' : '';
                        echo "<option value='$reg' $selected>$reg</option>";
                    }
                    echo '</select>';
                echo '</div>';

                echo '<div class="flex flex-col">';
                    echo '<label class="block mb-1">Statut</label>';
                    echo '<select name="statut" class="w-full p-2 mb-2 border rounded">';
                    $statut_val = $edit_school->statut ?? '';
                    echo '<option value="">— Selectează —</option>';
                    foreach (['Cu personalitate juridică', 'Arondată'] as $sv) {
                        $sel = ($statut_val === $sv) ? ' selected' : '';
                        echo '<option value="'.esc_attr($sv).'"'.$sel.'>'.esc_html($sv).'</option>';
                    }
                    echo '</select>';
                echo '</div>';

                echo '<div class="flex flex-col">';
                    echo '<label class="block mb-1">Tip</label>';
                    echo '<select name="tip" class="w-full p-2 mb-2 border rounded">';
                    $tip_val = $edit_school->tip ?? '';
                    echo '<option value="">— Selectează —</option>';
                    foreach (['Public', 'Privat'] as $tv) {
                        $sel = ($tip_val === $tv) ? ' selected' : '';
                        echo '<option value="'.esc_attr($tv).'"'.$sel.'>'.esc_html($tv).'</option>';
                    }
                    echo '</select>';
                echo '</div>';

                echo '<div class="flex flex-col">';
                    echo '<label class="block mb-1">Primul an TFR</label>';
                    echo '<input type="text" name="first_year_tfr" class="w-full p-2 mb-2 border rounded" placeholder="ex: 2024-2025" value="' . esc_attr($edit_school->first_year_tfr ?? '') . '">';
                echo '</div>';

            echo '</div>'; // end col 2

            // ===== COL 3
            echo '<div class="">';

                echo '<div class="flex flex-col">';
                    echo '<label class="block mb-1">Medie IRSE</label>';
                    echo '<input type="number" step="0.01" name="medie_irse" class="w-full p-2 mb-2 border rounded" value="' . esc_attr($edit_school->medie_irse ?? '') . '">';
                echo '</div>';

                echo '<div class="flex flex-col">';
                    echo '<label class="block mb-1">Scor IRSE</label>';
                    echo '<input type="number" step="0.01" name="scor_irse" class="w-full p-2 mb-2 border rounded" value="' . esc_attr($edit_school->scor_irse ?? '') . '">';
                echo '</div>';

                echo '<div class="flex flex-col">';
                    echo '<label class="block mb-1"><input type="checkbox" name="strategic" value="1"' . (!empty($edit_school->strategic) ? ' checked' : '') . '> Școală strategică</label>';
                echo '</div>';

                // Index vulnerabilitate TFR
                echo '<div class="flex flex-col">';
                echo '  <label for="index_vulnerabilitate_tfr" class="block mb-1">Index vulnerabilitate TFR</label>';
                echo '  <select id="index_vulnerabilitate_tfr" name="index_vulnerabilitate_tfr" class="w-full p-2 mb-2 border rounded">';
                $idx_val = isset($edit_school) ? (string)($edit_school->index_vulnerabilitate_tfr ?? '') : '';
                echo '    <option value="">— Selectează —</option>';
                foreach (['1','2','3','3NO','4','5'] as $iv) {
                    $sel = ($idx_val === $iv) ? ' selected' : '';
                    echo '    <option value="'.esc_attr($iv).'"'.$sel.'>'.esc_html($iv).'</option>';
                }
                echo '  </select>';
                echo '</div>';

                // Număr elevi SIIIR
                echo '<div class="flex flex-col">';
                echo '  <label for="numar_elevi_siiir" class="block mb-1">Număr elevi SIIIR</label>';
                echo '  <input type="number" step="1" min="0" id="numar_elevi_siiir" name="numar_elevi_siiir" class="w-full p-2 mb-2 border rounded" value="' . ( isset($edit_school) ? esc_attr($edit_school->numar_elevi_siiir ?? '') : '' ) . '">';
                echo '</div>';

                // Mediu
                echo '<div class="flex flex-col">';
                echo '  <label for="mediu" class="block mb-1">Mediu</label>';
                echo '  <select id="mediu" name="mediu" class="w-full p-2 mb-2 border rounded">';
                $mediu_val = isset($edit_school) ? ($edit_school->mediu ?? '') : '';
                echo '    <option value="">— Selectează —</option>';
                foreach (['urban' => 'Urban', 'rural' => 'Rural'] as $mk => $ml) {
                    $sel = ($mediu_val === $mk) ? ' selected' : '';
                    echo '    <option value="'.esc_attr($mk).'"'.$sel.'>'.esc_html($ml).'</option>';
                }
                echo '  </select>';
                echo '</div>';

                echo '<div class="flex items-center">';
                    echo '<button class="mt-2 button button-primary">' . ($edit_school ? 'Salvează modificările' : 'Adaugă școală') . '</button>';
                    if ($edit_school) {
                        echo ' <a href="' . admin_url('admin.php?page=edu-location-manager&tab=schools') . '" class="button">Anulează</a>';
                    }
                echo '</div>';

            echo '</div>'; // end col 3

        echo '</div>'; // grid

    echo '</form>';

    // Script pentru toggle coloane
    echo '<script>
    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll(".toggle-column").forEach(function(checkbox) {
            checkbox.addEventListener("change", function() {
                const colIndex = this.dataset.col;
                const cells = document.querySelectorAll(`td:nth-child(${colIndex}), th:nth-child(${colIndex})`);
                cells.forEach(cell => cell.style.display = this.checked ? "" : "none");
            });
        });
    });
    </script>';

    // === AJAX + prefill pentru Oraș/Sat și mediu corect ===
    $ajax_url = admin_url('admin-ajax.php');
    echo '<script>
    document.addEventListener("DOMContentLoaded", function(){
        var mediuInp   = document.getElementById("mediu_display");
        var countySel  = document.getElementById("edu_county");
        var citySel    = document.getElementById("edu_city");
        var villageSel = document.getElementById("edu_village");
        var nonce      = document.getElementById("edu_location_nonce").value;

        var prefillCounty  = document.getElementById("prefill_county_id") ? document.getElementById("prefill_county_id").value : "";
        var prefillCity    = document.getElementById("prefill_city_id") ? document.getElementById("prefill_city_id").value : "";
        var prefillVillage = document.getElementById("prefill_village_id") ? document.getElementById("prefill_village_id").value : "";

        function setMediu(){
            // dacă există sat selectat => rural, altfel urban (orașele încărcate aici sunt mereu parent_city_id IS NULL)
            if (villageSel && villageSel.value) {
                mediuInp.value = "rural";
            } else {
                mediuInp.value = "urban";
            }
        }

        function postForm(action, payload){
            var fd = new FormData();
            fd.append("action", action);
            fd.append("nonce",  nonce);
            Object.keys(payload || {}).forEach(function(k){ fd.append(k, payload[k]); });
            return fetch("'.$ajax_url.'", { method: "POST", credentials: "same-origin", body: fd });
        }

        function loadCities(countyId, cb){
            citySel.innerHTML    = "<option value=\'\'>Încărcare orașe...</option>";
            villageSel.innerHTML = "<option value=\'\'>Selectează comună/sat (dacă este cazul)</option>";
            villageSel.disabled  = true;

            if (!countyId){
                citySel.innerHTML = "<option value=\'\'>Selectează oraș</option>";
                return cb && cb();
            }

            postForm("edu_get_cities", { county_id: countyId })
              .then(function(r){ return r.text(); })
              .then(function(html){
                  citySel.innerHTML = html || "<option value=\'\'>Niciun oraș disponibil</option>";
                  citySel.disabled  = false;
                  cb && cb();
              })
              .catch(function(){
                  citySel.innerHTML = "<option value=\'\'>Eroare la încărcare</option>";
              });
        }

        function loadVillages(cityId, cb){
            villageSel.innerHTML = "<option value=\'\'>Încărcare sate...</option>";
            villageSel.disabled  = true;

            if (!cityId){
                villageSel.innerHTML = "<option value=\'\'>Selectează comună/sat (dacă este cazul)</option>";
                return cb && cb();
            }

            postForm("edu_get_villages", { city_id: cityId })
              .then(function(r){ return r.text(); })
              .then(function(html){
                  villageSel.innerHTML = html || "<option value=\'\'>Nicio comună sau sat</option>";
                  villageSel.disabled  = false;
                  cb && cb();
              })
              .catch(function(){
                  villageSel.innerHTML = "<option value=\'\'>Eroare la încărcare</option>";
              });
        }

        // Schimbări live
        if (countySel) countySel.addEventListener("change", function(){
            var cid = this.value;
            loadCities(cid, function(){
                // resetăm village
                villageSel.innerHTML = "<option value=\'\'>Selectează comună/sat (dacă este cazul)</option>";
                villageSel.disabled = true;
                setMediu();
            });
        });

        if (citySel) citySel.addEventListener("change", function(){
            var pid = this.value;
            loadVillages(pid, function(){
                setMediu();
            });
        });

        if (villageSel) villageSel.addEventListener("change", setMediu);

        // Prefill în modul Edit
        (function doPrefill(){
            if (!prefillCounty) { setMediu(); return; }
            // setează județul dacă nu e deja
            if (countySel && countySel.value !== prefillCounty){
                countySel.value = prefillCounty;
            }
            loadCities(prefillCounty, function(){
                if (prefillCity){
                    // selectăm orașul
                    var found = false;
                    Array.prototype.forEach.call(citySel.options, function(o){
                        if (o.value == prefillCity){ o.selected = true; found = true; }
                    });
                    if (!found){
                        // dacă nu e în listă (nu ar trebui), lăsăm UI să indice „Selectează oraș”
                    }
                    loadVillages(prefillCity, function(){
                        if (prefillVillage){
                            Array.prototype.forEach.call(villageSel.options, function(o){
                                if (o.value == prefillVillage){ o.selected = true; }
                            });
                        }
                        setMediu();
                    });
                } else {
                    setMediu();
                }
            });
        })();
    });
    </script>';

    // Toggle coloane (actualizat pentru noile coloane)
    echo '<div class="mb-4">';
    echo '<strong>Afișează coloane:</strong><br>';
    $columns = [
        'ID', 'Cod SIIIR', 'Denumire', 'Scurt', 'Județ', 'Oraș', 'Sat/Com.', 'Mediu', 'Regiune', 'Statut', 'Medie IRSE', 'Scor IRSE', 'Strategică', 'Acțiuni'
    ];
    foreach ($columns as $i => $label) {
        $colIndex = $i + 1;
        echo "<label style='margin-right: 10px;'><input type='checkbox' class='toggle-column' data-col='{$colIndex}' checked> {$label}</label>";
    }
    echo '</div>';

    // Total școli în DB
    $total_schools = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}edu_schools");

    // Paginație
    $per_page = 50;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $total_pages = max(1, ceil($total_schools / $per_page));
    $offset = ($current_page - 1) * $per_page;

    $schools = $wpdb->get_results($wpdb->prepare("
        SELECT s.*, c.name as city_name, ct.name as county_name, v.name as village_name
        FROM {$wpdb->prefix}edu_schools s
        JOIN {$wpdb->prefix}edu_cities c ON s.city_id = c.id
        JOIN {$wpdb->prefix}edu_counties ct ON c.county_id = ct.id
        LEFT JOIN {$wpdb->prefix}edu_cities v ON s.village_id = v.id
        ORDER BY ct.name, c.name, s.name
        LIMIT %d OFFSET %d
    ", $per_page, $offset));

    echo '<h2 class="mb-2 text-xl font-semibold">Lista școlilor <span style="font-weight:normal;font-size:14px;color:#666;">(' . $total_schools . ' total în DB)</span></h2>';

    // Paginație sus
    if ($total_pages > 1) {
        $base_url = admin_url('admin.php?page=edu-location-manager&tab=schools');
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo '<span class="displaying-num">' . $total_schools . ' elemente</span>';
        echo '<span class="pagination-links">';
        if ($current_page > 1) {
            echo ' <a class="button" href="' . esc_url($base_url . '&paged=1') . '">&laquo;</a>';
            echo ' <a class="button" href="' . esc_url($base_url . '&paged=' . ($current_page - 1)) . '">&lsaquo;</a> ';
        }
        echo ' <span class="paging-input">' . $current_page . ' din ' . $total_pages . '</span> ';
        if ($current_page < $total_pages) {
            echo ' <a class="button" href="' . esc_url($base_url . '&paged=' . ($current_page + 1)) . '">&rsaquo;</a>';
            echo ' <a class="button" href="' . esc_url($base_url . '&paged=' . $total_pages) . '">&raquo;</a>';
        }
        echo '</span></div></div>';
    }

    echo '<table class="wp-list-table widefat striped">';
    echo '<thead><tr>
        <th>ID</th><th>Cod SIIIR</th><th>Denumire</th><th>Scurt</th><th>Județ</th><th>Oraș</th><th>Sat/Com.</th><th>Mediu</th><th>Regiune</th><th>Statut</th><th>Medie IRSE</th><th>Scor IRSE</th><th>Strategică</th><th>Acțiuni</th>
    </tr></thead><tbody>';
    foreach ($schools as $row) {
        $is_strategic = $row->strategic ? '✅' : '';
        $village = $row->village_name ? $row->village_name : '-';
        $mediu   = $row->mediu ? $row->mediu : '-';
        $edit_url = admin_url("admin.php?page=edu-location-manager&tab=schools&edit_school={$row->id}");
        $delete_url = admin_url("admin.php?page=edu-location-manager&tab=schools&delete_school={$row->id}");

        echo "<tr>
            <td>{$row->id}</td>
            <td>{$row->cod}</td>
            <td>{$row->name}</td>
            <td>{$row->short_name}</td>
            <td>{$row->county_name}</td>
            <td>{$row->city_name}</td>
            <td>{$village}</td>
            <td>{$mediu}</td>
            <td>{$row->regiune_tfr}</td>
            <td>{$row->statut}</td>
            <td>{$row->medie_irse}</td>
            <td>{$row->scor_irse}</td>
            <td>{$is_strategic}</td>
            <td>
                <a href='{$edit_url}' class='button small'>Editează</a>
                <a href='{$delete_url}' class='text-red-600 button small' onclick='return confirm(\"Sigur dorești să ștergi această școală?\");'>Șterge</a>
            </td>
        </tr>";
    }
    echo '</tbody></table>';

    // Paginație jos
    if ($total_pages > 1) {
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo '<span class="pagination-links">';
        if ($current_page > 1) {
            echo ' <a class="button" href="' . esc_url($base_url . '&paged=1') . '">&laquo;</a>';
            echo ' <a class="button" href="' . esc_url($base_url . '&paged=' . ($current_page - 1)) . '">&lsaquo;</a> ';
        }
        echo ' <span class="paging-input">' . $current_page . ' din ' . $total_pages . '</span> ';
        if ($current_page < $total_pages) {
            echo ' <a class="button" href="' . esc_url($base_url . '&paged=' . ($current_page + 1)) . '">&rsaquo;</a>';
            echo ' <a class="button" href="' . esc_url($base_url . '&paged=' . $total_pages) . '">&raquo;</a>';
        }
        echo '</span></div></div>';
    }
}
