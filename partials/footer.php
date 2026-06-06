<?php
// Variables expected: $bp (string)
?>

<div class="footer small">© <?php echo date('Y'); ?> by Christian Meysing · Version <?php echo h(app_version()); ?> · COOL-Grades · Mitarbeit und Noten nach LBV erfassen</div>
</div><!-- wrap -->

<script>
(function(){
  var b=document.getElementById("burgerBtn");
  var n=document.getElementById("mainNav");
  if(!b||!n) return;
  b.addEventListener("click", function(){
    var open = n.classList.toggle("is-open");
    b.setAttribute("aria-expanded", open ? "true" : "false");
  });
})();
</script>

<script>
(function(){
  function openParentDetails(el){
    var parent = el ? el.parentElement : null;
    while(parent){
      if(parent.tagName && parent.tagName.toLowerCase() === 'details') parent.open = true;
      parent = parent.parentElement;
    }
  }

  function fieldLabel(el){
    if(!el) return 'Eingabe';
    if(el.dataset && el.dataset.label) return el.dataset.label;
    if(el.getAttribute('aria-label')) return el.getAttribute('aria-label');
    if(el.id){
      var linked = document.querySelector('label[for="'+CSS.escape(el.id)+'"]');
      if(linked){
        var text = linked.textContent || '';
        text = text.replace(/\s+/g,' ').trim();
        if(text) return text;
      }
    }
    var container = el.closest ? el.closest('label') : null;
    if(container){
      var labelText = (container.textContent || '').replace(/\s+/g,' ').trim();
      if(labelText) return labelText;
    }
    var prev = el.previousElementSibling;
    while(prev){
      if(prev.tagName && prev.tagName.toLowerCase() === 'label'){
        var prevText = (prev.textContent || '').replace(/\s+/g,' ').trim();
        if(prevText) return prevText;
      }
      prev = prev.previousElementSibling;
    }
    return el.name || el.placeholder || 'Eingabe';
  }

  function firstInvalidElement(form){
    var elements = form && form.elements ? Array.prototype.slice.call(form.elements) : [];
    for(var i=0;i<elements.length;i++){
      var el = elements[i];
      if(!el || el.disabled || typeof el.checkValidity !== 'function') continue;
      if(!el.checkValidity()) return el;
    }
    return null;
  }

  document.addEventListener('submit', function(ev){
    var form = ev.target;
    if(!(form instanceof HTMLFormElement)) return;
    if(form.noValidate) return;
    if(form.getAttribute('data-validate-ignore') === '1') return;
    if(ev.submitter && ev.submitter.formNoValidate) return;

    var invalid = firstInvalidElement(form);
    if(!invalid) return;

    ev.preventDefault();
    ev.stopPropagation();
    openParentDetails(invalid);

    var label = fieldLabel(invalid);
    var message = invalid.validity && invalid.validity.valueMissing
      ? 'Bitte das Pflichtfeld "' + label + '" ausfüllen.'
      : 'Bitte die Eingabe im Feld "' + label + '" prüfen.';
    window.alert(message);
    if(typeof invalid.focus === 'function') invalid.focus();
  }, true);

  document.addEventListener('submit', function(ev){
    var form = ev.target;
    if(!(form instanceof HTMLFormElement)) return;
    if(form.getAttribute('data-assignment-guard') !== '1') return;

    var classField = form.getAttribute('data-class-field') || 'class_id';
    var subjectField = form.getAttribute('data-subject-field') || 'subject_id';
    var classEl = form.elements[classField];
    var subjectEl = form.elements[subjectField];
    if(!classEl || !subjectEl) return;

    var classId = parseInt(classEl.value || '0', 10) || 0;
    var subjectId = parseInt(subjectEl.value || '0', 10) || 0;
    if(classId <= 0 || subjectId <= 0) return;

    var raw = form.getAttribute('data-allowed-combos') || '';
    if(!raw) return;
    var allowed = raw.split(',').filter(Boolean);
    var key = String(classId) + ':' + String(subjectId);
    if(allowed.indexOf(key) !== -1) return;

    ev.preventDefault();
    ev.stopPropagation();
    window.alert(form.getAttribute('data-assignment-message') || 'Keine Berechtigung für diese Klasse/dieses Fach.');
    if(typeof subjectEl.focus === 'function') subjectEl.focus();
  }, true);
})();
</script>

<script>
(function(){
  var watchedForms = Array.prototype.slice.call(document.querySelectorAll('form[data-dirty-watch="1"]'));
  if(!watchedForms.length){
    window.CoolGradesDirtyForms = {
      suppressNextNavigation: function(){}
    };
    return;
  }

  var message = 'Die Änderungen wurden noch nicht gespeichert, trotzdem fortfahren?';
  var states = new Map();
  var suppressUntil = 0;

  function isSuppressed(){
    return Date.now() < suppressUntil;
  }

  function suppressNextNavigation(){
    // Keep suppression active briefly so the native beforeunload dialog
    // does not appear immediately after a confirmed in-app warning.
    suppressUntil = Date.now() + 2000;
  }

  window.CoolGradesDirtyForms = {
    suppressNextNavigation: suppressNextNavigation
  };

  function serializeForm(form){
    var out = [];
    var elements = form.elements ? Array.prototype.slice.call(form.elements) : [];
    elements.forEach(function(el, idx){
      if(!el || !el.name || el.disabled) return;
      var tag = (el.tagName || '').toLowerCase();
      var type = (el.type || '').toLowerCase();
      if(tag === 'button') return;
      if(type === 'submit' || type === 'button' || type === 'reset' || type === 'image') return;

      if(tag === 'select' && el.multiple){
        var values = [];
        Array.prototype.forEach.call(el.options || [], function(opt){
          if(opt.selected) values.push(opt.value);
        });
        out.push([idx, el.name, values]);
        return;
      }

      if(type === 'checkbox' || type === 'radio'){
        out.push([idx, el.name, el.checked ? 1 : 0]);
        return;
      }

      if(type === 'file'){
        var files = [];
        Array.prototype.forEach.call(el.files || [], function(file){
          files.push(file.name + ':' + file.size);
        });
        out.push([idx, el.name, files]);
        return;
      }

      out.push([idx, el.name, el.value]);
    });
    return JSON.stringify(out);
  }

  function formState(form){
    return states.get(form) || null;
  }

  function isFormDirty(form){
    var state = formState(form);
    if(!state) return false;
    if(state.forceDirty) return true;
    return serializeForm(form) !== state.initial;
  }

  function updateFormDirty(form){
    var state = formState(form);
    if(!state) return;
    state.dirty = isFormDirty(form);
  }

  function anyDirty(exceptForm){
    return watchedForms.some(function(form){
      if(form === exceptForm) return false;
      return isFormDirty(form);
    });
  }

  function shouldIgnoreLink(anchor){
    if(!anchor) return true;
    if(anchor.hasAttribute('download')) return true;
    if(anchor.getAttribute('data-dirty-ignore') === '1') return true;
    if(anchor.target && anchor.target.toLowerCase() === '_blank') return true;
    var href = anchor.getAttribute('href') || '';
    if(!href || href.charAt(0) === '#') return true;
    if(/^javascript:/i.test(href)) return true;
    return false;
  }

  function confirmIfNeeded(form){
    if(isSuppressed()) return true;
    var ownDirty = !!form && isFormDirty(form);
    var otherDirty = anyDirty(form || null);
    if(form && ownDirty && !otherDirty) return true;
    if(!ownDirty && !otherDirty) return true;
    return window.confirm(message);
  }

  watchedForms.forEach(function(form){
    states.set(form, {
      initial: serializeForm(form),
      forceDirty: form.getAttribute('data-dirty-initial') === '1',
      dirty: form.getAttribute('data-dirty-initial') === '1'
    });

    ['input','change'].forEach(function(ev){
      form.addEventListener(ev, function(){
        updateFormDirty(form);
      });
    });
  });

  document.addEventListener('click', function(ev){
    var anchor = ev.target.closest ? ev.target.closest('a[href]') : null;
    if(!anchor || shouldIgnoreLink(anchor)) return;
    if(!anyDirty(null)) return;
    if(ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
    if(!window.confirm(message)){
      ev.preventDefault();
      ev.stopPropagation();
      return;
    }
    suppressNextNavigation();
  }, true);

  document.addEventListener('submit', function(ev){
    var form = ev.target;
    if(!(form instanceof HTMLFormElement)) return;

    if(form.getAttribute('data-dirty-ignore') === '1'){
      suppressNextNavigation();
      return;
    }

    var watched = form.getAttribute('data-dirty-watch') === '1';
    if(!watched && !anyDirty(null)) return;

    if(!confirmIfNeeded(watched ? form : null)){
      ev.preventDefault();
      ev.stopPropagation();
      return;
    }

    suppressNextNavigation();
  }, true);

  window.addEventListener('beforeunload', function(ev){
    if(isSuppressed()) return;
    if(!anyDirty(null)) return;
    ev.preventDefault();
    ev.returnValue = '';
  });
})();
</script>

<script>
// Client-side inactivity logout (for privacy on shared devices)
(function(){
  var min = parseInt((document.body && document.body.dataset && document.body.dataset.timeoutMin) || '0', 10);
  if(!min || min <= 0) return;
  var bp = (document.body && document.body.dataset && document.body.dataset.basePath) || '';
  var idleMs = min * 60 * 1000;
  var t = null;
  var countdown = document.getElementById('sessionTimeoutCountdown');
  var deadline = 0;
  var countdownInterval = null;

  function formatRemaining(ms){
    var totalSeconds = Math.max(0, Math.ceil(ms / 1000));
    var minutes = Math.floor(totalSeconds / 60);
    var seconds = totalSeconds % 60;
    if(minutes >= 60){
      var hours = Math.floor(minutes / 60);
      minutes = minutes % 60;
      return hours + ':' + String(minutes).padStart(2,'0') + ':' + String(seconds).padStart(2,'0');
    }
    return minutes + ':' + String(seconds).padStart(2,'0');
  }

  function updateCountdown(){
    if(!countdown) return;
    var remaining = deadline - Date.now();
    countdown.textContent = formatRemaining(remaining);
    countdown.classList.toggle('is-soon', remaining <= 60000);
  }

  function schedule(){
    if(t) clearTimeout(t);
    deadline = Date.now() + idleMs;
    updateCountdown();
    t = setTimeout(function(){
      // Trigger logout via POST so CSRF protection and timeout logging still work.
      var tokenEl = document.querySelector('meta[name="csrf-token"]');
      if(!tokenEl){
        window.location.href = bp + '/login.php?timeout=1';
        return;
      }
      var form = document.createElement('form');
      form.method = 'POST';
      form.action = bp + '/logout.php?timeout=1';
      form.style.display = 'none';

      var token = document.createElement('input');
      token.type = 'hidden';
      token.name = '_csrf';
      token.value = tokenEl.getAttribute('content') || '';
      form.appendChild(token);

      if(window.CoolGradesDirtyForms && typeof window.CoolGradesDirtyForms.suppressNextNavigation === 'function'){
        window.CoolGradesDirtyForms.suppressNextNavigation();
      }
      form.setAttribute('data-dirty-ignore', '1');
      form.setAttribute('data-validate-ignore', '1');
      document.body.appendChild(form);
      form.submit();
    }, idleMs);
  }

  if(countdown && !countdownInterval){
    countdownInterval = setInterval(updateCountdown, 1000);
  }

  // Reset timer on user interaction
  ['click','mousemove','keydown','touchstart','scroll'].forEach(function(ev){
    window.addEventListener(ev, schedule, {passive:true});
  });

  schedule();
})();
</script>

</body>
</html>
