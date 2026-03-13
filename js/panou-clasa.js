// panou-clasa.js — v3.2.2 (SEL + LIT client-side render + classOptions + scroll + save SEL)
console.log("✅ panou-clasa.js (v3.2.2) a fost încărcat");

function edusToast(msg, type = 'ok', ms = 2000) {
  const wrap = document.createElement('div');
  wrap.className = 'fixed inset-0 z-[9999] flex items-center justify-center';
  wrap.innerHTML = `
    <div class="absolute inset-0 bg-black/40"></div>
    <div class="relative px-5 py-3 rounded-2xl shadow-2xl text-base ${
      type === 'ok' ? 'bg-emerald-600 text-white' : 'bg-rose-600 text-white'
    } transform opacity-0 scale-95 transition-all duration-150">
      ${msg}
    </div>`;
  document.body.appendChild(wrap);

  // fade-in
  requestAnimationFrame(() => {
    const card = wrap.children[1];
    card.classList.remove('opacity-0', 'scale-95');
  });

  // fade-out + remove
  setTimeout(() => {
    const card = wrap.children[1];
    card.classList.add('opacity-0', 'scale-95');
    setTimeout(() => wrap.remove(), 160);
  }, ms);
}

jQuery(function ($) {
  // ——————————————————————————————
  //  classOptions: opțiuni pe nivel
  // ——————————————————————————————
  (function ensureClassOptions() {
    if (typeof window.classOptions === "function") return;
    const MAP = {
      prescolar: ["Grupa mica", "Grupa mare", "Grupa pregatitoare"],
      primar: ["Clasa 0", "Clasa 1", "Clasa 2", "Clasa 3", "Clasa 4"],
      gimnazial: ["Clasa 5", "Clasa 6", "Clasa 7", "Clasa 8"],
      liceu: ["Clasa 9", "Clasa 10", "Clasa 11", "Clasa 12", "Clasa 13"],
    };
    window.classOptions = function (code) {
      code = (code || "").toLowerCase().trim();
      return MAP[code] || [];
    };
  })();

  // ——————————————————————————————
  //  Panel helpers (scroll & close)
  // ——————————————————————————————
  function openPanel() {
    $("#overlay").removeClass("hidden");
    $("#questionnairePanel")
      .removeClass("translate-x-full", "right-0")
      .css({ height: "calc(100vh - 2rem)", display: "flex", "flex-direction": "column", "right": "1.5rem" });
    $("#questionnaireContent").css({ overflow: "auto", height: "100%" });
  }
  function closePanel() {
    $("#overlay").addClass("hidden");
    $("#questionnairePanel").addClass("translate-x-full", "right-0");
    $("#questionnaireContent").html("").attr("style", "");
  }

  let studentIndex = 0;

  function addStudentRow(idx, level = "") {
    const levelCode = ($("#level_code").val() || "").toLowerCase().trim();
    const clsOptions = (window.classOptions(levelCode) || [])
      .map((v) => `<option value="${v}">${v}</option>`)
      .join("");

    // utilitare UI – aceleași pentru toate input-urile
    const baseInput =
      "w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 shadow-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500";
    const baseSelect = baseInput + " pr-8";
    const td = "p-2 align-top";
    const trCls = "even:bg-slate-50 hover:bg-slate-50/60 transition-colors";

    const row = $(`
      <tr class="${trCls}" data-idx="${idx}">
        <td class="${td}">
          <input type="text" placeholder="Ex. Ana" name="students[${idx}][first_name]" required class="${baseInput}">
        </td>
        <td class="${td}">
          <input type="text" placeholder="Ex. Popescu" name="students[${idx}][last_name]" required class="${baseInput}">
        </td>
        <td class="${td}">
          <div class="flex items-center gap-2">
            <input type="number" placeholder="10" name="students[${idx}][age]" required min="1" max="20" class="${baseInput} w-24">
            <span class="text-xs text-slate-500">ani</span>
          </div>
        </td>
        <td class="${td}">
          <select name="students[${idx}][gender]" required class="${baseSelect}">
            <option value=""></option>
            <option value="M">M</option>
            <option value="F">F</option>
          </select>
        </td>
        <td class="${td} class_label_col">
          <select name="students[${idx}][class_label]" class="${baseSelect}">
            <option value=""></option>
            ${clsOptions}
          </select>
        </td>
        <td class="${td}">
          <select name="students[${idx}][sit_abs]" class="${baseSelect} sit-abs-select">
            <option value=""></option>
            <option value="Nu absentează deloc">Nu absentează deloc</option>
            <option value="Absentează uneori/rar">Absentează uneori/rar</option>
            <option value="Absentează des">Absentează des</option>
            <option value="Absentează foarte des">Absentează foarte des</option>
            <option value="Nu a venit niciodată">Nu a venit niciodată</option>
          </select>
        </td>
        <td class="${td} frecventa_col">
          <select name="students[${idx}][frecventa]" class="${baseSelect}">
            <option value=""></option>
            <option value="Nu">Nu</option>
            <option value="Da (1an)">Da (1an)</option>
            <option value="Da (2ani)">Da (2ani)</option>
            <option value="Da (3ani)">Da (3ani)</option>
            <option value="Da (4ani)">Da (4ani)</option>
            <option value="Date indisponibile">Date indisponibile</option>
          </select>
        </td>
        <td class="${td} bursa_col">
          <select name="students[${idx}][bursa]" class="${baseSelect}">
            <option value=""></option>
            <option value="Nu">Nu</option>
            <option value="Da">Da</option>
          </select>
        </td>
        <td class="${td}">
          <select name="students[${idx}][dif_limba]" class="${baseSelect}">
            <option value=""></option>
            <option value="Nu">Nu</option>
            <option value="Da">Da</option>
          </select>
        </td>
        <td class="${td} cauze-abs-cell" style="display:none;">
          <textarea name="students[${idx}][cauze_abs]" rows="2" placeholder="" class="${baseInput}"></textarea>
        </td>
        <td class="${td} risc-abandon-cell" style="display:none;">
          <select name="students[${idx}][risc_abandon]" class="${baseSelect}">
            <option value=""></option>
            <option value="Da">Da</option>
            <option value="Nu">Nu</option>
          </select>
        </td>
        <td class="${td}">
          <select name="students[${idx}][repeta_clasa]" class="${baseSelect}">
            <option value=""></option>
            <option value="Da">Da</option>
            <option value="Nu">Nu</option>
          </select>
        </td>
        <td class="${td}">
          <select name="students[${idx}][observation]" class="${baseSelect} obs-select">
            <option value=""></option>
            <option value="abandon">Abandon</option>
            <option value="plecat_tara">A plecat din țară</option>
            <option value="transferat_din">S-a transferat din clasă</option>
            <option value="transferat_in">S-a transferat în clasă</option>
          </select>
        </td>
        <td class="${td}">
          <textarea name="students[${idx}][alte_obs]" rows="2" placeholder="Ex. cazuri CES, situații speciale" class="${baseInput}"></textarea>
        </td>
        <td class="${td} demers-familie-cell">
          <span class="demers-na text-xs text-slate-400">Nu este cazul</span>
          <select name="students[${idx}][demers_familie]" class="${baseSelect} demers-select" style="display:none;">
            <option value=""></option>
            <option value="Da">Da</option>
            <option value="Nu">Nu</option>
            <option value="Nu a fost cazul">Nu a fost cazul</option>
          </select>
        </td>
        <td class="${td} demers-conducere-cell">
          <span class="demers-na text-xs text-slate-400">Nu este cazul</span>
          <select name="students[${idx}][demers_conducere]" class="${baseSelect} demers-select" style="display:none;">
            <option value=""></option>
            <option value="Da">Da</option>
            <option value="Nu">Nu</option>
            <option value="Nu a fost cazul">Nu a fost cazul</option>
          </select>
        </td>
        <td class="${td} demers-consilier-cell">
          <span class="demers-na text-xs text-slate-400">Nu este cazul</span>
          <select name="students[${idx}][demers_consilier]" class="${baseSelect} demers-select" style="display:none;">
            <option value=""></option>
            <option value="Da">Da</option>
            <option value="Nu">Nu</option>
            <option value="Nu a fost cazul">Nu a fost cazul</option>
          </select>
        </td>
        <td class="${td} notes-cell">
          <textarea name="students[${idx}][notes]" rows="2" placeholder="Detalii (opțional)" style="display:none;" class="${baseInput}"></textarea>
        </td>
        <td class="p-2 text-center">
          <button type="button" class="remove-student-row inline-flex items-center gap-1 rounded-lg bg-red-600 px-3 py-1 text-white shadow-sm hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
            </svg>
          </button>
        </td>
      </tr>
    `);

    // vizibilitate condițională (compat)
    if (!["Prescolar", "Primar"].includes(level)) {
      row.find(".frecventa_col").hide();
    }
    if (!["Primar", "Gimnazial"].includes(level)) {
      row.find(".bursa_col").hide();
    }

    $("#studentsContainer").append(row);
  }

  $("#studentCount").on("change", function () {
    const count = parseInt($(this).val(), 10) || 0;
    const level = $("#class_level").val(); // "Prescolar" / "Primar Mic" / "Primar Mare" / "Gimnazial" / "Liceu"
    $("#studentsContainer").empty();
    studentIndex = 0;
    for (let i = 0; i < count; i++) addStudentRow(studentIndex++, level);
  });

  $(document).on("click", ".remove-student-row", function () {
    $(this).closest("tr").remove();
  });

  $(document).on("input", 'input[name^="students"][name$="[age]"]', function () {
    const v = parseInt(this.value, 10);
    if (Number.isNaN(v)) return;
    if (v > 20) this.value = 20;
    if (v < 1)  this.value = 1;
  });

  $(document).on("change", ".obs-select", function () {
    const $row = $(this).closest("tr");
    const notesTa = $row.find(".notes-cell textarea");
    this.value ? notesTa.show() : notesTa.hide().val("");
  });

  // Conditional logic: sit_abs → cauze_abs, risc_abandon, demers_*
  $(document).on("change", ".sit-abs-select", function () {
    const $row = $(this).closest("tr, form");
    const val = $(this).val() || "";
    const showExtra = ["Absentează des", "Absentează foarte des", "Nu a venit niciodată"].includes(val);

    // cauze_abs & risc_abandon: show/hide
    $row.find(".cauze-abs-cell").toggle(showExtra);
    $row.find(".risc-abandon-cell").toggle(showExtra);
    if (!showExtra) {
      $row.find("[name*='cauze_abs']").val("");
      $row.find("[name*='risc_abandon']").val("");
    }

    // demers_*: show select / show "Nu este cazul"
    $row.find(".demers-familie-cell, .demers-conducere-cell, .demers-consilier-cell").each(function () {
      const $cell = $(this);
      if (showExtra) {
        $cell.find(".demers-na").hide();
        $cell.find(".demers-select").show();
      } else {
        $cell.find(".demers-na").show();
        $cell.find(".demers-select").hide().val("");
      }
    });
  });

  // Salvare elevi (FormData -> admin-ajax.php)
  $("#addStudentsForm").on("submit", function (e) {
    e.preventDefault();
    const $form = $(this);
    const genId = $form.find("input[name=generation_id]").val() || "";
    //const classId = $form.find("input[name=class_id]").val() || "";

    const fd = new FormData();
    fd.append("action", "edu_add_students");
    fd.append("generation_id", genId);
    //fd.append("class_id", classId); // compat
    fd.append("class_id", "0");

    // construim students[0][first_name] ... ca să fim 100% compatibili cu PHP
    $form.find("tbody tr").each(function () {
      const $tr = $(this);
      const i = $tr.data("idx");
      const pick = (name) =>
        $tr.find(`[name="students[${i}][${name}]"]`).val() || "";
      [
        "first_name",
        "last_name",
        "age",
        "gender",
        "class_label",
        "sit_abs",
        "frecventa",
        "bursa",
        "dif_limba",
        "cauze_abs",
        "risc_abandon",
        "repeta_clasa",
        "observation",
        "alte_obs",
        "demers_familie",
        "demers_conducere",
        "demers_consilier",
        "notes",
      ].forEach((f) => {
          let v = pick(f);
          if (f === "age") {
            const n = parseInt(v, 10);
            if (!Number.isNaN(n)) {
              v = String(Math.max(1, Math.min(20, n))); // clamp 1..20
            }
          }
          fd.append(`students[${i}][${f}]`, v);
        });
      });

    fetch(ajaxurl, { method: "POST", body: fd, credentials: "same-origin" })
      .then((r) => r.json())
      .then((res) => {
        console.log("[add_students][debug]", res);
        if (!res || !res.success) {
          alert(
            "Eroare: " +
              (res && res.data
                ? typeof res.data === "string"
                  ? res.data
                  : res.data.message || "Nu s-au putut salva elevii."
                : "Nu s-au putut salva elevii.")
          );
          return;
        }
        const inserted = Number((res.data && res.data.inserted) || 0);
        if (inserted <= 0) {
          alert(
            "Nu s-a salvat niciun elev. " +
              (res.data && res.data.errors
                ? "\n" + JSON.stringify(res.data.errors)
                : "")
          );
          return;
        }
        edusToast("Elevii au fost adăugați! (" + inserted + ")", 'ok', 2000);
        setTimeout(() => location.reload(), 2200);
      })
      .catch((err) => {
        console.error("[add_students][fetch]", err);
        edusToast("Eroare la salvare elevi.", 'error', 2000);
      });
  });

  $(document).on("click", ".delete-student", function () {
    if (!confirm("Ești sigur că vrei să ștergi acest elev?")) return;
    const id = $(this).data("id");

    $.post(
      ajaxurl,
      { action: "delete_student", student_id: id },
      function (response) {
        if (response.success) {
          // Remove the main row
          const mainRow = $("#student-row-" + id);
          // Remove the details row
          const detailsRow = $("#student-details-" + id);
          // Also try closest details (legacy structure)
          const detailsEl = mainRow.length ? null : $(".delete-student[data-id='" + id + "']").closest("details");

          if (mainRow.length) {
            mainRow.fadeOut(300, () => mainRow.remove());
            detailsRow.fadeOut(300, () => detailsRow.remove());
          } else if (detailsEl && detailsEl.length) {
            detailsEl.fadeOut(300, () => detailsEl.remove());
          }
          edusToast("Elevul a fost șters.", "ok", 2000);
        } else {
          edusToast("Eroare la ștergere elev.", "error", 2000);
        }
      }
    );
  });

  $("#studentCount").trigger("change");

  function loadStudents(classId) {
    const container = $("#studentList");
    container.html('<div class="text-gray-500">Se încarcă elevii...</div>');

    $.post(
      ajaxurl,
      { action: "get_students", class_id: classId, generation_id: classId },
      function (response) {
        if (!response.success) {
          container.html(
            '<div class="text-red-600">Eroare la încărcarea elevilor.</div>'
          );
          return;
        }
        container.html(response.data);
        // butoanele SEL/LIT au handlers mai jos (delegat)
      }
    );
  }

  $(document).ready(function () {
    const classId = $("#studentList").data("class-id");
    if (classId) loadStudents(classId);
  });

  $(document).on("click", ".toggle-details", function () {
    const id = $(this).data("id");
    $("#student-details-" + id).slideToggle();
  });

  // === EDITARE ELEV — inline, sub rând ===

    // Încarcă formularul de editare și îl inserează sub rând
  $(document).on("click", ".edit-student, .edit-student-inline", function () {
    const studentId = $(this).data("id");
    if (!studentId) return;

    const $row = findStudentRowEl(studentId, $(this));
    if (!$row.length) return;

    // dacă e deja deschis sub alt elev, închidem
    closeAnyInlineEditor();

    // placeholder sub rând
    const $placeholder = $(
      `<tr class="edus-edit-inline table-row" id="edit-inline-${studentId}"></tr>`
    );
    $placeholder.insertAfter($row);

    // cerem formularul HTML de la server
    $.post(
      ajaxurl,
      { action: "edu_get_student_edit_form", student_id: studentId },
      function (res) {
        if (!res || !res.success) {
          alert(
            res && res.data ? res.data : "Eroare la încărcarea formularului."
          );
          $placeholder.remove();
          return;
        }
        $placeholder.html(res.data.html).hide().slideDown(150);

        // Populăm <select> Clasă pe baza nivelului
        const $form = $placeholder.find("form[id^=edus-edit-form-]");
        const level = ($form.data("level") || "").toLowerCase().trim();
        const $sel = $form.find(".edus-class-select");
        if ($sel.length) {
          let opts = '<option value=""></option>';
          const list =
            (window.classOptions && window.classOptions(level)) || [];
          list.forEach((v) => {
            opts += `<option value="${v}">${v}</option>`;
          });
          $sel.html(opts);
          const cur = $sel.data("current") || "";
          if (cur) $sel.val(cur);
        }

        // Observație => mențiuni show/hide
        const $obs = $form.find(".edus-observation");
        const $notesWrap = $form.find(".edus-notes-wrap");
        const syncNotes = () => {
          $obs.val()
            ? $notesWrap.show()
            : $notesWrap.hide().find("textarea").val("");
        };
        $obs.on("change", syncNotes);
        syncNotes();
      }
    );
  });

  // Anulează editarea
  $(document).on("click", ".edus-cancel-edit", function () {
    const id = $(this).data("id");
    const $wrap = $(`#edit-inline-${id}`);
    if ($wrap.length)
      $wrap.slideUp(150, function () {
        $(this).remove();
      });
  });

  // Salvează
  $(document).on("click", ".edus-save-edit", function () {
    const id = $(this).data("id");
    const $form = $(`#edus-edit-form-${id}`);
    if (!$form.length) return;

    const payload = $form.serialize(); // include action=edu_update_student

    $.ajax({
      url: ajaxurl,
      method: "POST",
      data: payload,
      dataType: "json",
      success: function (res) {
        if (!res || !res.success) {
          alert(res && res.data ? res.data : "Eroare la salvare.");
          return;
        }
        // Închidem editorul și re-încărcăm lista
        const $wrap = $(`#edit-inline-${id}`);
        if ($wrap.length)
          $wrap.slideUp(120, function () {
            $(this).remove();
          });

        const classId = $("#studentList").data("class-id");
        if (classId) {
          // refolosim loaderul deja existent
          // (ai deja loadStudents(classId) definit)
          // ca să evităm flicker mare, dăm un mic delay
          setTimeout(() => loadStudents(classId), 120);
        }
      },
      error: function () {
        alert("Eroare de rețea/server la salvare.");
      },
    });
  });

  // Utilities mici pentru găsit „containerul rândului” în listă
  function findStudentRowEl(studentId, $startBtn) {
    // încarci lista via get_students — recomandat să pui data attr pe wrapper
    // încercăm în ordine câteva variante:
    let $row = $(`#student-row-${studentId}`);
    if ($row.length) return $row;

    $row = $(`[data-student-row="${studentId}"]`);
    if ($row.length) return $row;

    // fallback: urcă până la cel mai apropiat container de elev
    if ($startBtn && $startBtn.length) {
      const $c1 = $startBtn.closest(`[id="student-details-${studentId}"]`);
      if ($c1.length) return $c1;
      const $c2 = $startBtn.closest(
        "details, tr, li, .student-item, .student-row"
      );
      if ($c2.length) return $c2;
    }
    // dacă nu găsim nimic, plasăm în #studentList
    return $("#studentList");
  }

    // Helper: află numele elevului robust din DOM (dataset pe buton/rând sau din celule)
  function getStudentName($startBtn, studentId) {
    // 1) încearcă dataset-ul de pe buton
    const dsBtn = $startBtn && $startBtn.length ? $startBtn.get(0).dataset : null;
    let nm = (dsBtn && (dsBtn.name || dsBtn.studentName)) ? (dsBtn.name || dsBtn.studentName) : "";
    if (nm && String(nm).trim()) return String(nm).trim();

    // 2) caută rândul elevului
    const $row = findStudentRowEl(studentId, $startBtn);
    if ($row && $row.length) {
      // 2.a) dataset pe rând
      const dsRow = $row.get(0).dataset || {};
      nm = dsRow.studentName || "";
      if (nm && String(nm).trim()) return String(nm).trim();

      // 2.b) selectori obișnuiți pentru nume
      let $el = $row.find(".student-name, .student_full_name, a.toggle-details, .full-name, .name").first();
      if ($el.length) {
        nm = ($el.text() || "").trim();
        if (nm) return nm;
      }

      // 2.c) compune din prenume+nume dacă sunt separate
      const pick = (sel) => {
        const $t = $row.find(sel).first();
        return ($t.text() || $t.val() || "").trim();
      };
      const first = pick("[data-first-name], .first-name, .prenume");
      const last  = pick("[data-last-name],  .last-name,  .nume");
      nm = (first + " " + last).trim();
      if (nm) return nm;
    }
    return "";
  }

  // închide orice editor deschis
  function closeAnyInlineEditor() {
    $(".edus-edit-inline").slideUp(150, function () {
      $(this).remove();
    });
  }



  // ——————————————————————————————
  //           Chestionare — SEL (v3.2.3)
  // ——————————————————————————————

  let __SEL_CTX = null; // contextul ultimei evaluări deschise (student/modul)
  let __SEL_LAST_BTN = null; // butonul apăsat (pt. name=value în payload)

  // Helper: găsește formularul SEL (primul form din #questionnaireContent care NU e LIT)
  function findSelForm() {
    const $form = $("#questionnaireContent")
      .find("form")
      .filter(function () {
        return !$(this).is('[data-type="lit"]');
      })
      .first();
    return $form.length ? $form : $();
  }

  // Injectează câmp hidden dacă lipsește
  function ensureHidden($form, name, val) {
    let $inp = $form.find(`input[name="${name}"]`);
    if (!$inp.length)
      $inp = $(`<input type="hidden" name="${name}">`).appendTo($form);
    if (val !== undefined && val !== null && $inp.val() === "") $inp.val(val);
  }

  function wireSelForm() {
    const $form = findSelForm();
    if (!$form.length) {
      console.warn("[SEL] Nu am găsit formularul în questionnaireContent.");
      return;
    }

    // Context fallback din deschidere
    const classId = $("#studentList").data("class-id") || "";
    const studentId = __SEL_CTX?.studentId || "";
    const modulSlug = __SEL_CTX?.modulSlug || "";

    // Asigură câmpurile minime pentru backend (dacă templatul nu le are)
    ensureHidden($form, "action", "save_questionnaire"); // fallback – nu suprascrie dacă există
    ensureHidden($form, "student_id", studentId);
    ensureHidden($form, "modul_type", "sel");
    ensureHidden($form, "modul", modulSlug);
    ensureHidden($form, "class_id", classId);

    // memorăm ce buton s-a apăsat (ca să includem name/value în payload)
    $("#questionnaireContent")
      .off("click.selBtnRemember")
      .on("click.selBtnRemember", "button, input[type=submit]", function () {
        __SEL_LAST_BTN = this;
      });

    // Un singur handler (namespaced) ca să evităm „dublări”
    $form.off("submit.saveSel").on("submit.saveSel", function (e) {
      e.preventDefault();

      // Validare completare integrală la salvare permanentă
      const isFinal =
        (__SEL_LAST_BTN && __SEL_LAST_BTN.dataset?.status === "final") ||
        $form.find('input[name="status"]').val() === "final";

      if (isFinal) {
        const missing = [];
        $form.find(".question-wrapper").each(function () {
          const $wrap = $(this);
          const $radios = $wrap.find('input[type="radio"]');
          if ($radios.length && !$radios.filter(":checked").length) {
            const label = $wrap.find(".question-label").text().trim();
            missing.push(label);
            $wrap.css("outline", "2px solid #ef4444");
          } else {
            $wrap.css("outline", "");
          }
        });
        if (missing.length) {
          alert(
            "Nu poți salva permanent. Completează următoarele câmpuri:\n\n" +
              missing.map((m) => "• " + m).join("\n")
          );
          // Scroll la prima întrebare necompletată
          const $first = $form.find('.question-wrapper').filter(function () {
            return $(this).css("outline").indexOf("solid") !== -1;
          }).first();
          if ($first.length) {
            $first[0].scrollIntoView({ behavior: "smooth", block: "center" });
          }
          return;
        }
      }

      // construim payload din serializeArray + includem butonul apăsat
      const arr = $form.serializeArray();
      if (__SEL_LAST_BTN && __SEL_LAST_BTN.name) {
        arr.push({
          name: __SEL_LAST_BTN.name,
          value: __SEL_LAST_BTN.value || "",
        });
      }
      const payload = $.param(arr);

      const ajaxAction =
        $form.find('input[name="action"]').val() || "save_questionnaire";
      console.log("[SEL][submit] action=", ajaxAction, "| payload=", payload);

      $.ajax({
        url: ajaxurl,
        method: "POST",
        data: payload,
        // IMPORTANT: nu setăm dataType — acceptăm și JSON, și HTML
        success: function (res, status, xhr) {
          let parsed = null,
            isJson = false;
          if (typeof res === "string") {
            try {
              parsed = JSON.parse(res);
              isJson = true;
            } catch (_) {}
          } else if (res && typeof res === "object") {
            parsed = res;
            isJson = true;
          }

          if (isJson) {
            console.log("[SEL][response JSON]", parsed);
            if (parsed.success) {
              alert("✔️ Chestionar SEL salvat.");
              closePanel();
              const cid = $("#studentList").data("class-id");
              if (cid) {
                setTimeout(() => {
                  $.post(
                    ajaxurl,
                    {
                      action: "get_students",
                      class_id: cid,
                      generation_id: cid,
                    },
                    function (r) {
                      if (r && r.success) $("#studentList").html(r.data);
                    }
                  );
                }, 150);
              }
            } else {
              const msg = parsed.data
                ? typeof parsed.data === "string"
                  ? parsed.data
                  : parsed.data.message || JSON.stringify(parsed.data)
                : "Eroare necunoscută.";
              alert("Eroare la salvare SEL: " + msg);
              console.warn("[SEL][error JSON]", parsed);
            }
          } else {
            // Răspuns HTML – multe implementări WP vechi trimit un view/redirect
            console.log("[SEL][response HTML]", res);
            alert("✔️ Chestionar SEL salvat.");
            closePanel();
            const cid = $("#studentList").data("class-id");
            if (cid) {
              setTimeout(() => {
                $.post(
                  ajaxurl,
                  { action: "get_students", class_id: cid, generation_id: cid },
                  function (r) {
                    if (r && r.success) $("#studentList").html(r.data);
                  }
                );
              }, 150);
            }
          }
        },
        error: function (xhr, status, err) {
          console.error("[SEL][ajax error]", status, err, xhr?.responseText);
          alert("Eroare la salvare SEL (rețea/server). Vezi consola.");
        },
      });
    });

    // Forțează orice buton relevant să trimită formularul,
    // chiar dacă e type="button" sau are atribute custom
    $("#questionnaireContent")
      .off("click.saveSelBtns")
      .on(
        "click.saveSelBtns",
        "button, input[type=submit], [data-submit='save'], .btn-save-sel, .save-sel",
        function (e) {
          const $btn = $(this);
          const type = ($btn.attr("type") || "").toLowerCase();

          // Dacă e submit nativ, îl lăsăm să declanșeze submit-ul (pe care îl interceptăm)
          // Altfel, convertim orice click relevant într-un submit.
          const isNativeSubmit =
            type === "submit" || $btn.is("input[type=submit]");
          const hasSaveIntent = $btn.is(
            ".save-sel, .btn-save-sel, [data-submit='save'], [data-ajax-action], [data-status]"
          );

          if (!isNativeSubmit && hasSaveIntent) {
            e.preventDefault();
            // dacă butonul are o acțiune personalizată/status, injectează-le în form
            const $form = findSelForm();
            if ($form.length) {
              const customAction = $btn.data("ajaxAction");
              const status = $btn.data("status");
              if (customAction) ensureHidden($form, "action", customAction);
              if (status) ensureHidden($form, "status", status);
              $form.trigger("submit");
            }
          }
          // altfel (submit nativ) nu prevenim — submit-ul va fi interceptat mai sus
        }
      );

    console.log("[SEL] Formular cablat pentru submit AJAX.");
  }

  $(document).on("click", ".start-questionnaire[data-type='sel']", function () {
    const studentId = this.dataset.id;
    const modulType = "sel";
    const modulSlug = this.dataset.modul || "";
    const classId = $("#studentList").data("class-id") || "";

    __SEL_CTX = { studentId, modulSlug, classId };

    const rawNameSel = this.dataset.name || this.dataset.studentName || "";
    window.__SEL_STUDENT_NAME = rawNameSel || getStudentName($(this), studentId);

    openPanel();

    $.post(
      ajaxurl,
      {
        action: "load_questionnaire_form",
        student_id: studentId,
        modul_type: modulType,
        modul: modulSlug,
        class_id: classId,
      },
      function (response) {
        if (response && response.success) {
          $("#questionnaireContent").html(response.data);
          try {
            const t = $("#questionnaireTitle");
            if (t.length && window.__SEL_STUDENT_NAME) {
              const base = t.text().trim();
              if (!base.includes(window.__SEL_STUDENT_NAME)) {
                t.html("<span>" + base + "</span> " + window.__SEL_STUDENT_NAME);
              }
            }
          } catch (_e) {}

          // stilizare & preselectări
          paintAllSelQuestions();
          preloadSelectedAnswers();
          // IMPORTANT: cablăm formularul proaspăt injectat
          wireSelForm();

          // diagnostic rapid
          const $f = findSelForm();
          if ($f.length) {
            console.log(
              "[SEL] form găsit. action=",
              $f.find('input[name="action"]').val(),
              "| has lit? ",
              $f.is('[data-type="lit"]')
            );
          } else {
            console.warn("[SEL] niciun form detectat în conținut.");
          }
        } else {
          alert(
            "Eroare: " +
              (response && response.data
                ? response.data
                : "Nu s-a putut încărca formularul.")
          );
          console.warn("[SEL][load error]", response);
        }
      }
    );
  });

  // ——————————————————————————————
  //        Chestionare — LIT (client-side)
  // ——————————————————————————————
  const LIT = {
    esc: (s) =>
      String(s ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;"),

    qToHtml(q, withHr) {
      const req = q.required ? 'data-required="1"' : "";
      const cond =
        q.cond &&
        q.cond.field &&
        Array.isArray(q.cond.values) &&
        q.cond.values.length
          ? `data-cond-field="${this.esc(
              q.cond.field
            )}" data-cond-values="${this.esc(
              q.cond.values.join("|")
            )}" style="display:none;"`
          : "";
      if (q.type === "select") {
        const opts = (q.choices || [])
          .map((c) => {
            const v = "value" in c ? c.value : c;
            const l = "label" in c ? c.label : c;
            const sel =
              q.default != null && String(q.default) === String(v)
                ? " selected"
                : "";
            return `<option value="${this.esc(v)}"${sel}>${this.esc(
              l
            )}</option>`;
          })
          .join("");
        return `
          <div class="question-wrapper py-3 px-3 flex items-center gap-x-4 justify-between" ${req} ${cond}>
            <label class="flex-1 question-label block text-sm font-medium">${this.esc(
              q.label
            )}</label>
            <select name="${this.esc(q.name)}" class="flex-1 border rounded border-slate-300 text-sky-800 px-2 py-1">
              <option value="">—</option>${opts}
            </select>
          </div>`;
      }
      const min = q.min ?? 0,
        max = q.max ?? 10,
        step = q.step ?? "1";
      return `
        <div class="question-wrapper py-3 px-3 flex items-center gap-x-4 justify-between" ${req} ${cond}>
          <label class="flex-1 question-label block text-sm font-medium mb-2">${this.esc(
            q.label
          )} <span class="ml-2 text-xs text-gray-500">(${min}–${max})</span></label>
          <div class="flex-1 flex items-center justify-end gap-3">
            <input type="number" name="${this.esc(
              q.name
            )}" min="${min}" max="${max}" step="${this.esc(
        step
      )}" placeholder="0" class="w-32 px-3 py-1 text-sm border rounded border-slate-300 text-sky-800" style="min-width:8rem">
          </div>
        </div>`;
    },

    renderForm(schema, studentId, modulSlug, levelLabel, studentName) {
      const qs = Array.isArray(schema?.questions) ? schema.questions : [];
      let sectionBreakInserted = false;
      const body = qs
        .map((q, i) => {
          let prefix = "";
          if (!sectionBreakInserted && q.cond) {
            sectionBreakInserted = true;
            const condField = q.cond.field || "";
            const condVals = (q.cond.values || []).join("|");
            prefix = `<div class="cond-break py-4 my-2 border-t-2 border-dashed border-amber-300 bg-amber-50 rounded-lg px-4" data-cond-field="${this.esc(condField)}" data-cond-values="${this.esc(condVals)}">
              <p class="text-sm font-semibold text-amber-800">Elev identificat cu recomandare de intervenție remedială, aplicați și evaluarea de nivel Clasa Pregătitoare</p>
            </div>`;
          }
          return prefix + this.qToHtml(q, i < qs.length - 1);
        })
        .join("");
      const stage = /-t1$/i.test(modulSlug) ? "T1" : "T0";
      const displayName = (studentName && String(studentName).trim())
        ? this.esc(studentName)
        : `Elev #${this.esc(studentId)}`;
      console.log({ studentId, studentName, displayName });
      return `
        <div class="px-4 py-2 bg-slate-800 text-white flex items-center justify-between gap-x-4 sticky top-0 z-50">
          <h3 id="questionnaireTitle" class="text-lg font-semibold"><span>LIT</span> ${this.esc(
            levelLabel || ""
          )} · ${stage} . ${displayName}</h3>
          <button id="closeQuestionnaire" class="text-2xl">&times;</button>
        </div>
        <div class="flex flex-col justify-between h-full">
          <div class="hidden mb-3 text-sm text-gray-600 items-center gap-2">
            <strong>Modul:</strong> <span class="px-2 py-0.5 rounded bg-slate-100">${this.esc(
              modulSlug
            )}</span>
            <span class="text-gray-400">•</span>
            <span>Schemă: ${this.esc(
              window.__LIT_SCHEMA?.source || "n/a"
            )}</span>
          </div>
          <form id="questionnaireForm" class="flex flex-col justify-between h-full" data-type="lit" data-student="${this.esc(
            studentId
          )}" data-modul="${this.esc(modulSlug)}">
            <div class="mb-2 p-4 max-w-[30rem] mx-auto">
              <div id="questionnaire-progress" class="mb-1 text-sm text-slate-600">Completat: 0</div>
              <div class="h-1 bg-gray-100 rounded"><div id="progressBar" class="h-1 bg-emerald-500 rounded" style="width:0%"></div></div>
            </div>
            <div class="px-4 pb-8 max-w-[30rem] mx-auto">
              ${body}
            </div>
            <div class="sticky bottom-0 left-0 right-0 z-50 flex items-center justify-center px-4 py-3 bg-slate-800">
              <div class="flex items-center justify-end gap-2 px-6">
                <button type="button" class="save-lit px-4 py-2 text-white border rounded bg-white/10 border-white/20 save-questionnaire hover:bg-white/80 hover:text-sky-800" data-status="draft">Salvează Temporar</button>
                <button type="button" class="save-lit flex items-center gap-2 px-4 py-2 text-white rounded bg-emerald-600 rounded-br-xl save-questionnaire hover:bg-emerald-700" data-status="final">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                  Salvează Permanent</button>
              </div>
            </div>
          </form>
        </div>`;
    },

    visibleByCond($form, $wrap) {
      const field = $wrap.attr("data-cond-field");
      if (!field) return true;
      const allowed = String($wrap.attr("data-cond-values") || "")
        .split("|")
        .map((v) => v.trim().toUpperCase())
        .filter(Boolean);
      const $ctrl = $form.find(`[name="${field}"]`);
      const val = ($ctrl.val() || "").toUpperCase();
      const show = allowed.includes(val);
      if (show) {
        $wrap.get(0).style.display = "";
      } else {
        $wrap.get(0).style.display = "none";
        $wrap.find("input,select").each(function () {
          $(this).val($(this).is("select") ? "" : "");
        });
      }
      return show;
    },

    updateRangesLive($form) {
      // number inputs don't need output sync
    },

    updateProgress() {
      const $form = $("#questionnaireForm");
      if (!$form.length) return;
      const $wraps = $form.find(".question-wrapper:visible");
      const total = $wraps.length;
      const ans = $wraps.filter(function () {
        const $w = $(this);
        if ($w.find('input[type="radio"]:checked').length) return true;
        const $n = $w.find('input[type="number"]');
        if ($n.length) return $n.val() !== "" && $n.val() !== null;
        const $s = $w.find("select");
        if ($s.length) return $s.val() !== "" && $s.val() !== null;
        return false;
      }).length;
      const pct = total ? Math.round((100 * ans) / total) : 0;
      $("#questionnaire-progress").text(
        `Completat: ${ans} din ${total} (${pct}%)`
      );
      $("#progressBar").css("width", pct + "%");
      return { total, ans, pct };
    },

    wireUp(formSelector, modulSlug, studentId) {
      const $form = $(formSelector);
      this.updateRangesLive($form);

      const applyAll = () => {
        // collect all visibility decisions first, then apply (avoids re-entrant events)
        const els = $form.find(".question-wrapper, .cond-break").toArray();
        const decisions = [];
        for (let i = 0; i < els.length; i++) {
          const el = els[i];
          const field = el.getAttribute("data-cond-field");
          if (!field) continue;
          const allowed = (el.getAttribute("data-cond-values") || "")
            .split("|").map(v => v.trim().toUpperCase()).filter(Boolean);
          const $ctrl = $form.find(`[name="${field}"]`);
          const val = ($ctrl.val() || "").toUpperCase();
          decisions.push({ el, show: allowed.includes(val) });
        }
        // apply all at once
        for (let i = 0; i < decisions.length; i++) {
          const { el, show } = decisions[i];
          el.style.display = show ? "" : "none";
          if (!show) {
            $(el).find("input,select").each(function () {
              this.value = "";
            });
          }
        }
        this.updateProgress();
      };
      applyAll();

      $form.on("change input", "input,select", () => applyAll());
      // backup: document-level delegation for selects (ensures conditional fields update)
      $(document).off("change.litCond").on("change.litCond", "#questionnaireForm select", () => applyAll());

      // prefill din DB
      $.post(
        ajaxurl,
        {
          action: "get_questionnaire",
          modul_type: "lit",
          modul: modulSlug,
          student_id: studentId,
        },
        (res) => {
          if (res?.success && res?.data?.results) {
            const r = res.data.results;
            Object.keys(r).forEach((k) => {
              const $el = $form.find(`[name="${k}"]`);
              if ($el.length) $el.val(r[k]).trigger("change");
            });
          }
          this.updateProgress();
        }
      );

      // salvare
      $(document)
        .off("click.saveLit")
        .on("click.saveLit", ".save-lit", (e) => {
          const status = e.currentTarget.dataset.status || "draft";

          if (status === "final") {
            let ok = true;
            $form.find(".question-wrapper:visible").each(function () {
              const $w = $(this);
              const hasRadio =
                $w.find('input[type="radio"]:checked').length > 0;
              const $r = $w.find('input[type="number"]');
              const hasRange = $r.length && $r.val() !== "";
              const $s = $w.find("select");
              const hasSel = $s.length && $s.val() !== "";
              if (!(hasRadio || hasRange || hasSel)) {
                ok = false;
                if (!$w.find(".error-message").length) {
                  $w.addClass("border border-red-500").append(
                    '<p class="error-message text-sm text-red-600 mt-1">⚠️ Câmp obligatoriu</p>'
                  );
                }
              } else {
                $w.removeClass("border border-red-500")
                  .find(".error-message")
                  .remove();
              }
            });
            if (!ok) {
              alert("Completează toate câmpurile obligatorii.");
              return;
            }
            if (
              !confirm(
                "După salvare, datele nu mai pot fi modificate. Continui?"
              )
            )
              return;
          }

          const prog = LIT.updateProgress();
          const fd = new FormData();
          fd.append("action", "save_questionnaire_lit");
          fd.append("modul_type", "lit");
          fd.append("modul", modulSlug);
          fd.append("student_id", studentId);
          fd.append("status", status);
          fd.append("completion", String(prog?.pct || 0));

          // valori + scor (suma range vizibile)
          let scoreSum = 0;
          const breakdown = [];
          $form.find(".question-wrapper:visible").each(function () {
            const $w = $(this);
            const $inp = $w.find("input,select").first();
            if (!$inp.length) return;
            const name = $inp.attr("name");
            const label = $w.find(".question-label").text().trim();
            const val = $inp.val();
            fd.append(name, val ?? "");

            if ($inp.is('[type="number"]')) {
              const max = Number($inp.attr("max") || 0);
              const v = Number(val || 0);
              if (isFinite(v)) scoreSum += v;
              breakdown.push({ name, label, value: v, max });
            } else {
              breakdown.push({ name, label, value: val });
            }
          });

          //fd.append("score_total", String(scoreSum));
          fd.append("score_breakdown", JSON.stringify(breakdown));
          fd.append(
            "score_meta",
            JSON.stringify({
              module: modulSlug,
              answered: prog?.ans || 0,
              total_questions: prog?.total || 0,
              source: window.__LIT_SCHEMA?.source || "unknown",
            })
          );

          fetch(ajaxurl, {
            method: "POST",
            body: fd,
            credentials: "same-origin",
          })
            .then((r) => r.json())
            .then((data) => {
              if (!data?.success)
                throw new Error(data?.data || "Eroare la salvare.");
              alert("✔️ Chestionar LIT salvat (" + status + ").");
              closePanel();
              const cid = $("#studentList").data("class-id");
              if (cid) {
                setTimeout(() => {
                  $.post(
                    ajaxurl,
                    {
                      action: "get_students",
                      class_id: cid,
                      generation_id: cid,
                    },
                    function (r) {
                      if (r && r.success) $("#studentList").html(r.data);
                    }
                  );
                }, 150);
              }
            })
            .catch((err) => {
              console.error("[LIT][save]", err);
              alert("Eroare la salvare LIT.");
            });
        });
    },
  };

  // LIT: deschidere formular client-side
  $(document).on("click", ".start-questionnaire[data-type='lit']", function () {
    const studentId = this.dataset.id;
    const modulSlug = this.dataset.modul || ""; // ex. literatie-primar-t0 / literatie-primar-t1
    const levelLabel = $("#studentList").data("class-level") || "";
    const rawName = this.dataset.name || this.dataset.studentName || "";
    const studentName = rawName || getStudentName($(this), studentId);

    openPanel();

    const schema = window.__LIT_SCHEMA || { slug: modulSlug, questions: [] };
    const html = LIT.renderForm(schema, studentId, modulSlug, levelLabel, studentName);
    $("#questionnaireContent").html(html);
    LIT.wireUp("#questionnaireForm", modulSlug, studentId);
  });

  // închidere panou (comun)
  $(document).on("click", "#closeQuestionnaire", closePanel);

  // ——————————————————————————————
  // Progres generic (SEL — pentru formularele încărcate server-side)
  // ——————————————————————————————
  function isAnswered($w) {
    if ($w.find('input[type="radio"]:checked').length) return true;
    const r = $w.find('input[type="number"]');
    if (r.length) return r.val() !== "" && r.val() !== null;
    const s = $w.find("select");
    if (s.length) return s.val() !== "" && s.val() !== null;
    return false;
  }
  function updateQuestionnaireProgress() {
    const $form = $("#questionnaireForm");
    if (!$form.length) return;
    const $wraps = $form.find(".question-wrapper:visible");
    const total = $wraps.length;
    const ans = $wraps.filter(function () {
      return isAnswered($(this));
    }).length;
    $("#questionnaire-progress").text("Completat: " + ans + " din " + total);
    $("#progressBar").css(
      "width",
      (total ? Math.round((100 * ans) / total) : 0) + "%"
    );
  }
  function preloadSelectedAnswers() {
    $(".question-wrapper").each(function () {
      const radios = $(this).find('input[type="radio"]');
      const $label = $(this).find(".question-label");
      radios.each(function (index) {
        if ($(this).is(":checked")) {
          const $option = $(this).closest(".selectable-option");
          $option.addClass("selected");
          if (index === 0) $option.addClass("selected-green");
          else if (index === 1) $option.addClass("selected-blue");
          else if (index === 2) $option.addClass("selected-orange");
          $label.addClass("active");
        }
      });
    });
    updateQuestionnaireProgress();
  }
  $(document).on(
    "input change",
    "#questionnaireForm input[type=number], #questionnaireForm select, #questionnaireForm input[type=radio]",
    function () {
      const $w = $(this).closest(".question-wrapper");
      $w.removeClass("border border-red-500");
      $w.find(".error-message").remove();
      updateQuestionnaireProgress();
    }
  );
  $(document).ajaxSuccess(function (event, xhr, settings) {
    if (
      settings.data &&
      settings.data.includes("action=load_questionnaire_form")
    ) {
      preloadSelectedAnswers();
    }
  });

  // ———————————————— Helpers de stilizare pentru opțiunile SEL (tile-uri) ———————————————— //
  function paintSelQuestion($wrap) {
    const $opts = $wrap.find(".selectable-option");
    $opts.removeClass("selected selected-green selected-blue selected-orange");

    const $checked = $wrap.find('input[type="radio"]:checked');
    if ($checked.length) {
      const $opt = $checked.closest(".selectable-option");
      $opt.addClass("selected");

      // culoare în funcție de indexul opțiunii în întrebare (0/1/2)
      const idx = $opts.index($opt);
      if (idx === 0) $opt.addClass("selected-green");
      else if (idx === 1) $opt.addClass("selected-blue");
      else if (idx === 2) $opt.addClass("selected-orange");
    }
  }

  function paintAllSelQuestions() {
    $("#questionnaireForm .question-wrapper").each(function () {
      paintSelQuestion($(this));
    });
  }

  // Click pe „tile” (nu pe input-ul ascuns)
  $(document).on("click", "#questionnaireForm .selectable-option", function () {
    const $opt = $(this);
    const $wrap = $opt.closest(".question-wrapper");
    const $radio = $opt.find('input[type="radio"]');

    if ($radio.length) {
      $radio.prop("checked", true).trigger("change");
      paintSelQuestion($wrap);
      updateQuestionnaireProgress();
    }
  });

  // Dacă se schimbă radio-ul din alte motive (ex. tastatură), restilizăm
  $(document).on(
    "change",
    "#questionnaireForm input[type='radio']",
    function () {
      paintSelQuestion($(this).closest(".question-wrapper"));
      updateQuestionnaireProgress();
    }
  );
});


(function () {
  const table = document.getElementById("studentsTable");
  if (!table) return;
  const ths = table.querySelectorAll("thead th");
  if (!ths.length) return;

  // Define helpful texts by column index (0-based). Adjust as needed.
  const help = [
    "Nr. curent",
    "Prenumele elevului",
    "Numele elevului",
    "Vârsta elevului (max 20 ani)",
    "Genul elevului",
    "Observație administrativă (transfer/abandon)",
    "Absenteism pe perioada curentă",
    "A frecventat elevul gradinita?",
    "Beneficiaza elevul de bursa sociala sau alt sprijin material?",
    "Limba vorbită acasă diferă de limba de predare?",
    "Note / detalii adiționale",
    "Acțiuni (salvare/ștergere)",
  ];

  // Create tooltip element
  const tip = document.createElement("div");
  tip.style.position = "fixed";
  tip.style.zIndex = "9999";
  tip.style.padding = "6px 8px";
  tip.style.borderRadius = "8px";
  tip.style.fontSize = "12px";
  tip.style.lineHeight = "1.2";
  tip.style.maxWidth = "260px";
  tip.style.boxShadow = "0 10px 20px rgba(0,0,0,.15)";
  tip.style.border = "1px solid rgba(15,23,42,.12)";
  tip.style.background = "white";
  tip.style.color = "#0f172a";
  tip.style.pointerEvents = "none";
  tip.style.opacity = "0";
  tip.style.transition = "opacity .12s ease";
  document.body.appendChild(tip);

  function showTip(text, x, y) {
    tip.textContent = text;
    tip.style.left = Math.round(x + 12) + "px";
    tip.style.top = Math.round(y + 12) + "px";
    tip.style.opacity = "1";
  }
  function hideTip() {
    tip.style.opacity = "0";
  }

  ths.forEach((th, idx) => {
    const text = th.getAttribute("data-help") || help[idx] || "";
    if (!text) return;
    th.style.position = "relative";
    th.addEventListener("mouseenter", (e) =>
      showTip(text, e.clientX, e.clientY)
    );
    th.addEventListener("mousemove", (e) =>
      showTip(text, e.clientX, e.clientY)
    );
    th.addEventListener("mouseleave", hideTip);
    // also support focus for accessibility
    th.setAttribute("tabindex", "0");
    th.addEventListener("focus", (e) => {
      const rect = th.getBoundingClientRect();
      showTip(text, rect.left + rect.width / 2, rect.top + 8);
    });
    th.addEventListener("blur", hideTip);
  });
})();