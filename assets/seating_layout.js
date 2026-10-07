/*
 * COOL-Grades – Sitzplan-Editor und Anzeige freier Sitzpläne (seit 1.81.6)
 *
 * Datenmodell (identisch mit lib/seating_plans.php):
 *   layout = {version:1, tables:[{type, x, y, rot}, ...]}
 *   seats  = [[student_id, tischNr (1-basiert), platzNr (1-basiert)], ...]
 * Koordinaten: Tafel/vorne liegt im Modell oben (kleines y). Die Ansicht
 * "vom Lehrertisch" dreht nur die Darstellung um 180°.
 *
 * Öffentliche Schnittstelle: window.CoolSeating.mountView(...) und
 * window.CoolSeating.mountEditor(...).
 */
(function(){
  'use strict';
  var SW = 36, SH = 15, PITCH = 74;

  /* ---------------- Tischtypen ---------------- */
  function rowTable(k, label){
    var seats = [];
    for(var i=0;i<k;i++) seats.push({dx:(i-(k-1)/2)*PITCH, n:[0,1]});
    return {label:label, w:k*PITCH+4, h:44, seats:seats};
  }
  function island(k){
    var m = Math.floor(k/2), odd = k%2===1, seats = [], i;
    for(i=0;i<m;i++) seats.push({dx:(i-(m-1)/2)*PITCH, n:[0,-1]});
    for(i=0;i<m;i++) seats.push({dx:(i-(m-1)/2)*PITCH, n:[0,1]});
    if(odd) seats.push({dx:0, n:[1,0]});
    return {label:'Insel ('+k+')', w:Math.max(m,1)*PITCH+4, h:76, seats:seats};
  }
  function conference(k){
    var m = Math.max(1, Math.ceil((k-2)/2)), seats = [], i;
    for(i=0;i<m;i++) seats.push({dx:(i-(m-1)/2)*PITCH, n:[0,-1]});
    for(i=0;i<m;i++) seats.push({dx:(i-(m-1)/2)*PITCH, n:[0,1]});
    seats.push({dx:0, n:[1,0]}, {dx:0, n:[-1,0]});
    return {label:'Konferenztisch ('+(2*m+2)+')', w:m*PITCH+4, h:90, seats:seats};
  }
  var TYPES = {
    t1: rowTable(1,'Einzeltisch'), t2: rowTable(2,'Zweiertisch'), t3: rowTable(3,'Dreiertisch'),
    chair: {label:'Einzelstuhl', w:0, h:0, seats:[{dx:0, n:[0,1]}]},
    lt: {label:'Lehrertisch', w:150, h:56, seats:[]},
    board: {label:'Tafel', w:320, h:24, seats:[]}
  };
  for(var ki=3;ki<=8;ki++) TYPES['i'+ki] = island(ki);
  function getType(key){
    if(TYPES[key]) return TYPES[key];
    var m = /^k(\d+)$/.exec(key);
    if(m){ TYPES[key] = conference(parseInt(m[1],10)); return TYPES[key]; }
    return {label:key, w:60, h:40, seats:[]};
  }
  function confKey(k){ if(k%2) k++; return 'k'+Math.max(4, Math.min(30, k)); }

  /* ---------------- Geometrie ---------------- */
  function seatAbs(t, i){
    var T = getType(t.type), st = T.seats[i];
    var a = t.rot*Math.PI/180, c = Math.cos(a), s = Math.sin(a);
    var lx = st.n[0], ly = st.n[1];
    var nx = lx*c-ly*s, ny = lx*s+ly*c;
    if(t.type==='chair') return {x:t.x, y:t.y, nx:nx, ny:ny};
    var tx = lx?0:st.dx, ty = lx?st.dx:0;
    var ox = tx*c-ty*s, oy = tx*s+ty*c;
    var d = (lx?T.w/2:T.h/2)+4+SW*Math.abs(nx)+SH*Math.abs(ny);
    return {x:t.x+ox+nx*d, y:t.y+oy+ny*d, nx:nx, ny:ny};
  }
  function allSeats(tables){
    var out = [];
    tables.forEach(function(t, ti){
      getType(t.type).seats.forEach(function(_, si){
        var p = seatAbs(t, si);
        out.push({t:t, ti:ti, si:si, x:p.x, y:p.y, nx:p.nx, ny:p.ny});
      });
    });
    return out;
  }
  function visualOrder(tables){
    return allSeats(tables).sort(function(a,b){ return (Math.round(a.y/30)-Math.round(b.y/30)) || (a.x-b.x); });
  }
  function fitView(tables){
    var x1=0, y1=0, x2=1000, y2=700;
    tables.forEach(function(t){
      var T = getType(t.type), a = t.rot*Math.PI/180, c = Math.abs(Math.cos(a)), s = Math.abs(Math.sin(a));
      var rx = (T.w*c+T.h*s)/2+12, ry = (T.w*s+T.h*c)/2+12;
      x1=Math.min(x1,t.x-rx); x2=Math.max(x2,t.x+rx); y1=Math.min(y1,t.y-ry); y2=Math.max(y2,t.y+ry);
    });
    allSeats(tables).forEach(function(p){
      x1=Math.min(x1,p.x-48); x2=Math.max(x2,p.x+48); y1=Math.min(y1,p.y-30); y2=Math.max(y2,p.y+30);
    });
    return {x:Math.floor(x1-10), y:Math.floor(y1-10), w:Math.ceil(x2-x1+20), h:Math.ceil(y2-y1+20)};
  }
  function clamp(v,a,b){ return Math.min(b, Math.max(a, v)); }

  /* ---------------- Vorlagen ---------------- */
  function mk(type,x,y,rot){ return {type:type, x:Math.round(x), y:Math.round(y), rot:rot||0}; }
  function fixtures(){ return [mk('board',500,28,0), mk('lt',190,104,0)]; }
  function ring(T,count,R,cx,cy,sx,sy){
    for(var i=0;i<count;i++){
      var phi=-Math.PI/2+i*2*Math.PI/count, ax=R*sx, ay=R*sy, px=ax*Math.cos(phi), py=ay*Math.sin(phi);
      var g=Math.hypot(px/ax/ax,py/ay/ay), ux=px/ax/ax/g, uy=py/ay/ay/g;
      T.push(mk('chair',cx+px,cy+py,Math.atan2(-ux,uy)*180/Math.PI));
    }
  }
  function uShape(T,b,s,cx,y0){
    var step=158, x0=cx-(b-1)*step/2, lx=x0-step/2+22, rx=cx+(cx-lx), i;
    for(i=0;i<s;i++){ T.push(mk('t2',lx,y0+i*step,90)); T.push(mk('t2',rx,y0+i*step,-90)); }
    var by=y0+Math.max(s,1)*step-30;
    for(i=0;i<b;i++) T.push(mk('t2',x0+i*step,by,0));
  }
  /* Vorschlagswerte der Parameter je Vorlage, abhängig von der Schülerzahl n */
  function paramDefaults(tpl, n){
    var t2 = Math.ceil(n/2);
    return {
      cols: 3, size: 2, rows: clamp(Math.ceil(n/6),1,10),
      back: tpl==='doppelu' ? Math.max(3,Math.round(t2/4)) : Math.max(2,Math.round(t2/3)),
      isl: 4,
      blocks: tpl==='bankett' ? (n>24?2:1) : Math.ceil(n/14)
    };
  }
  function buildTemplate(tpl, P, n){
    var T = [], i, r, c;
    n = Math.max(1, n);
    if(tpl==='reihen' || tpl==='fisch'){
      var fisch = tpl==='fisch', type='t'+clamp(P.size,1,3), W=getType(type).w;
      var px=W+(fisch?110:70), py=fisch?128:100, cols=clamp(P.cols,1,8), rows=clamp(P.rows,1,12), x0=500-(cols-1)*px/2, mid=(cols-1)/2;
      for(r=0;r<rows;r++) for(c=0;c<cols;c++) T.push(mk(type,x0+c*px,(fisch?220:205)+r*py, fisch?(c<mid?20:c>mid?-20:0):0));
    } else if(tpl==='u'){
      var t2=Math.ceil(n/2), b=clamp(P.back,1,t2), sides=Math.max(0,t2-b), l=Math.ceil(sides/2);
      uShape(T,b,l,500,240);
    } else if(tpl==='doppelu'){
      var tt=Math.ceil(n/2), bo=clamp(P.back,3,12), bi=bo-2, so=Math.max(2,Math.ceil((tt-bo-bi+2)/4));
      uShape(T,bo,so,500,240); uShape(T,bi,so-1,500,240);
    } else if(tpl==='konferenz'){
      var cnt=clamp(P.blocks,1,6), per=Math.ceil(n/cnt), key=confKey(per), Wk=getType(key).w;
      var kc=(cnt>=4||(cnt>=2&&Wk<330))?2:1, kpx=Wk+260, kx0=500-(kc-1)*kpx/2;
      for(i=0;i<cnt;i++) T.push(mk(key,kx0+(i%kc)*kpx,280+Math.floor(i/kc)*250,0));
    } else if(tpl==='bankett'){
      var bc=clamp(P.blocks,1,4), len=Math.ceil(Math.ceil(n/4)/bc);
      for(var bi2=0;bi2<bc;bi2++){
        var cx=500+(bi2-(bc-1)/2)*300;
        for(i=0;i<len;i++){ T.push(mk('t2',cx-22,250+i*158,90)); T.push(mk('t2',cx+22,250+i*158,-90)); }
      }
    } else if(tpl==='inseln'){
      var k=clamp(P.isl,3,8), icnt=Math.ceil(n/k), ityp='i'+k, iW=getType(ityp).w, icol=icnt<=4?2:3, ipx=iW+150, ix0=500-(icol-1)*ipx/2;
      for(i=0;i<icnt;i++) T.push(mk(ityp,ix0+(i%icol)*ipx,255+Math.floor(i/icol)*220,0));
    } else if(tpl==='hufeisen'){
      var g=Math.ceil(n/6), hc=g<=4?2:3, hx0=500-(hc-1)*340/2;
      for(i=0;i<g;i++) uShape(T,1,1,hx0+(i%hc)*340,230+Math.floor(i/hc)*300);
    } else if(tpl==='kreis'){
      var R=Math.max(200,n*84/(2*Math.PI));
      ring(T,n,R,500,175+R*0.75,1.3,0.75);
    } else if(tpl==='fishbowl'){
      var fk=clamp(Math.round(n/4),4,8), Ri=Math.max(110,fk*84/(2*Math.PI)), Ro=Math.max(Ri+150,(n-fk)*84/(2*Math.PI*1.05));
      var fcy=170+Ro*0.8; ring(T,fk,Ri,500,fcy,1.2,0.8); ring(T,Math.max(0,n-fk),Ro,500,fcy,1.3,0.8);
    } else if(tpl==='edv'){
      var es=Math.ceil(n/3), eb=Math.max(0,n-2*es), half=Math.max(eb*84/2+130,380), elx=500-half, erx=500+half;
      for(i=0;i<es;i++){ T.push(mk('t1',elx,220+i*84,-90)); T.push(mk('t1',erx,220+i*84,90)); }
      var eby=220+es*84+70, ex0=500-(eb-1)*42;
      for(i=0;i<eb;i++) T.push(mk('t1',ex0+i*84,eby,180));
    }
    return fixtures().concat(T);
  }

  /* ---------------- Namen ---------------- */
  /* Kurze, eindeutige Platzbeschriftung: "Anna B.", bei Gleichheit "Anna Be." usw. */
  function shortLabels(students){
    var map = {}, byLabel = {};
    function mkLabel(s, len){ return s.first.split(' ')[0]+' '+(len>=s.last.length ? s.last : s.last.slice(0,len)+'.'); }
    students.forEach(function(s){ s._len = 1; });
    for(var round=0; round<6; round++){
      byLabel = {};
      students.forEach(function(s){ var l = mkLabel(s, s._len); (byLabel[l]=byLabel[l]||[]).push(s); });
      var clash = false;
      Object.keys(byLabel).forEach(function(l){ if(byLabel[l].length>1){ clash = true; byLabel[l].forEach(function(s){ s._len++; }); } });
      if(!clash) break;
    }
    students.forEach(function(s){ map[s.id] = mkLabel(s, s._len); delete s._len; });
    return map;
  }

  /* ---------------- SVG-Darstellung ---------------- */
  function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function readable(a){ a=((a%360)+360)%360; if(a>90&&a<=270) a-=180; return a; }
  function f(n){ return n.toFixed(1); }

  /*
   * opts: {teacher:bool, labels:{id:label}, seatClass:function(studentId, seat)->string,
   *        selTable:index|null, emptyText:string, numberTables:bool}
   */
  function renderSvg(svg, tables, occ, view, opts){
    var v = view, F = !!opts.teacher, cx = v.x+v.w/2, cy = v.y+v.h/2, add = F?180:0;
    function P(x,y){ return F ? [2*cx-x, 2*cy-y] : [x,y]; }
    svg.setAttribute('viewBox', v.x+' '+v.y+' '+v.w+' '+v.h);
    var h = '<rect class="cs-room" x="'+v.x+'" y="'+v.y+'" width="'+v.w+'" height="'+v.h+'" rx="10"/>';
    for(var gx=Math.ceil(v.x/50)*50; gx<v.x+v.w; gx+=50) h += '<line class="cs-grid" x1="'+gx+'" y1="'+v.y+'" x2="'+gx+'" y2="'+(v.y+v.h)+'"/>';
    for(var gy=Math.ceil(v.y/50)*50; gy<v.y+v.h; gy+=50) h += '<line class="cs-grid" x1="'+v.x+'" y1="'+gy+'" x2="'+(v.x+v.w)+'" y2="'+gy+'"/>';
    var num = 0, texts = [];
    tables.forEach(function(t, ti){
      var T = getType(t.type); if(!T.w) return;
      var isT = t.type==='lt', isB = t.type==='board'; if(!isT && !isB) num++;
      var p = P(t.x,t.y), r = t.rot+add;
      h += '<g class="cs-table'+(isT?' cs-teacher':'')+(isB?' cs-board':'')+(opts.selTable===ti?' cs-sel':'')+'" data-t="'+ti+'" transform="translate('+p[0]+' '+p[1]+') rotate('+r+')">'
         + '<rect x="'+(-T.w/2)+'" y="'+(-T.h/2)+'" width="'+T.w+'" height="'+T.h+'" rx="5"/></g>';
      var cls = isB?'cs-boardtxt':isT?'cs-tabletxt':'cs-tableno', txt = isB?'TAFEL':isT?'Lehrertisch':(opts.numberTables===false?'':num);
      if(txt!=='') texts.push('<text class="'+cls+'" transform="translate('+p[0]+' '+p[1]+') rotate('+readable(r)+')" y="4.5" text-anchor="middle">'+txt+'</text>');
    });
    allSeats(tables).forEach(function(s){
      var id = occ[s.ti] ? occ[s.ti][s.si] : null;
      var label = id ? (opts.labels[id] || '?') : null;
      var cls = 'cs-seat'+(id?' cs-filled':'')+(opts.seatClass ? (' '+(opts.seatClass(id, s)||'')) : '');
      var p = P(s.x,s.y), nx = F?-s.nx:s.nx, ny = F?-s.ny:s.ny;
      var ax = Math.abs(nx)>Math.abs(ny), lx = ax?0:26, ly = ax?10:0;
      var bx = ax?Math.sign(nx)*(SW-1):nx*(SW-8), by = ax?ny*(SH-6):Math.sign(ny)*(SH-1);
      h += '<g class="'+cls+'" data-t="'+s.ti+'" data-s="'+s.si+'"'+(id?' data-student-id="'+id+'"':'')+' transform="translate('+f(p[0])+' '+f(p[1])+')">'
         + '<rect x="'+(-SW)+'" y="'+(-SH)+'" width="'+(2*SW)+'" height="'+(2*SH)+'" rx="8"/>'
         + '<line x1="'+f(bx-lx)+'" y1="'+f(by-ly)+'" x2="'+f(bx+lx)+'" y2="'+f(by+ly)+'"/></g>';
      var txt = label !== null ? label : (opts.emptyText || '');
      if(txt){
        var fit = txt.length>11 ? ' textLength="66" lengthAdjust="spacingAndGlyphs"' : '';
        texts.push('<text class="cs-seattxt'+(label!==null?'':' cs-empty')+'" x="'+f(p[0])+'" y="'+f(p[1]+4)+'" text-anchor="middle"'+fit+'>'+esc(txt)+'</text>');
      }
    });
    svg.innerHTML = h+'<g class="cs-labels">'+texts.join('')+'</g>';
  }

  function occFromSeats(tables, seats){
    var occ = tables.map(function(t){ return getType(t.type).seats.map(function(){ return null; }); });
    (seats||[]).forEach(function(s){
      var ti = s[1]-1, si = s[2]-1;
      if(occ[ti] && si>=0 && si<occ[ti].length) occ[ti][si] = s[0];
    });
    return occ;
  }

  /* ---------------- Anzeige (Mitarbeitserfassung, Kompetenz-Beobachtung) ---------------- */
  /*
   * cfg: {layout, seats, students:[{id,first,last}], teacher:true, onSeatClick:fn(studentId), seatClass:fn}
   */
  function mountView(el, cfg){
    var tables = (cfg.layout && cfg.layout.tables) ? cfg.layout.tables : [];
    var occ = occFromSeats(tables, cfg.seats);
    var labels = shortLabels((cfg.students||[]).map(function(s){ return {id:s.id, first:s.first, last:s.last}; }));
    var svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
    svg.setAttribute('class','cs-svg cs-view');
    svg.setAttribute('role','img');
    svg.setAttribute('aria-label','Sitzplan');
    el.innerHTML = '';
    var wrap = document.createElement('div'); wrap.className = 'cs-stage'; wrap.appendChild(svg); el.appendChild(wrap);
    renderSvg(svg, tables, occ, fitView(tables), {teacher: cfg.teacher!==false, labels:labels, seatClass:cfg.seatClass, emptyText:''});
    if(cfg.onSeatClick){
      svg.addEventListener('click', function(e){
        var g = e.target.closest('.cs-seat[data-student-id]');
        if(g) cfg.onSeatClick(parseInt(g.getAttribute('data-student-id'),10), g);
      });
    }
    return {svg:svg};
  }

  /* ---------------- Editor ---------------- */
  /*
   * cfg: {layout|null, seats, students:[{id,first,last}], templates:[[key,label,desc],...],
   *       form:HTMLFormElement, layoutInput, seatsInput}
   */
  function mountEditor(root, cfg){
    var students = (cfg.students||[]).slice();
    var labels = shortLabels(students.map(function(s){ return {id:s.id, first:s.first, last:s.last}; }));
    var fullName = {}; students.forEach(function(s){ fullName[s.id] = s.last+', '+s.first; });
    var n = Math.max(1, students.length);
    var tplList = (cfg.templates||[]).concat([['leer','Leer','Leerer Raum mit Tafel und Lehrertisch. Tische über „Hinzufügen“ einsetzen.']]);
    var hasLayout = !!(cfg.layout && cfg.layout.tables && cfg.layout.tables.length);
    var S = {
      tpl: hasLayout ? null : tplList[0][0],
      P: paramDefaults(hasLayout ? 'reihen' : tplList[0][0], n),
      tables: [], occ: [], mode:'arrange', sel:null, pick:null,
      teacher: true, view:null, drag:null, dirty:false
    };
    if(hasLayout){
      S.tables = cfg.layout.tables.map(function(t){ return {type:t.type, x:t.x, y:t.y, rot:t.rot}; });
      S.occ = occFromSeats(S.tables, cfg.seats);
    } else {
      S.tables = buildTemplate(S.tpl, S.P, n);
      S.occ = occFromSeats(S.tables, []);
    }

    root.innerHTML =
      '<div class="cs-toolbar">'
      + '<div class="cs-row"><span class="cs-lbl">Vorlage</span><span class="cs-chips" data-role="tpl"></span></div>'
      + '<div class="cs-row" data-role="params"></div>'
      + '<div class="cs-row"><span class="cs-lbl">Ansicht</span>'
      +   '<button type="button" class="btn small" data-view="teacher">Vom Lehrertisch (Tafel unten)</button>'
      +   '<button type="button" class="btn small secondary" data-view="class">Von hinten (Tafel oben)</button>'
      +   '<span class="muted small cs-stat" data-role="stat"></span></div>'
      + '<div class="muted small" data-role="note"></div>'
      + '</div>'
      + '<div class="cs-tabs" role="tablist">'
      +   '<button type="button" class="cs-tab" data-mode="arrange" role="tab">1 · Tische anordnen</button>'
      +   '<button type="button" class="cs-tab" data-mode="assign" role="tab">2 · Plätze belegen</button>'
      + '</div>'
      + '<div class="cs-main"><div class="cs-stage"><svg class="cs-svg" xmlns="http://www.w3.org/2000/svg" aria-label="Sitzplan-Editor"></svg></div>'
      + '<div class="cs-panel" data-role="panel"></div></div>';
    var $ = function(sel){ return root.querySelector(sel); };
    var svg = $('svg.cs-svg');

    function markDirty(){ S.dirty = true; }
    function relayout(){ S.view = fitView(S.tables); }

    function applyTemplate(keepOrder){
      var order = keepOrder ? visualOrder(S.tables).map(function(s){ return S.occ[s.ti][s.si]; }).filter(Boolean) : [];
      S.tables = buildTemplate(S.tpl, S.P, n);
      S.occ = occFromSeats(S.tables, []);
      visualOrder(S.tables).forEach(function(s, i){ S.occ[s.ti][s.si] = order[i] || null; });
      S.sel = null; S.pick = null; markDirty(); relayout(); render();
    }

    function seatedSet(){ var set = {}; S.occ.forEach(function(row){ row.forEach(function(id){ if(id) set[id]=true; }); }); return set; }
    function unplaced(){ var set = seatedSet(); return students.filter(function(s){ return !set[s.id]; }); }
    function seatOf(id){ for(var ti=0;ti<S.occ.length;ti++){ var si=S.occ[ti].indexOf(id); if(si>=0) return [ti,si]; } return null; }

    function paramsHtml(){
      var P = S.P, t = S.tpl, h = '';
      function num(key, label, min, max){ return '<label class="cs-param"><span class="cs-lbl">'+label+'</span><input class="input small" type="number" data-p="'+key+'" min="'+min+'" max="'+max+'" value="'+P[key]+'"></label>'; }
      if(t==='reihen' || t==='fisch'){
        h += num('cols','Tischspalten',1,8)+num('rows','Reihen',1,12)
          + '<label class="cs-param"><span class="cs-lbl">Plätze je Tisch</span><select class="input small" data-p="size">'
          + [1,2,3].map(function(v){ return '<option'+(P.size===v?' selected':'')+'>'+v+'</option>'; }).join('')+'</select></label>';
      } else if(t==='u' || t==='doppelu'){
        h += num('back', t==='doppelu'?'Tische hinten (außen)':'Tische hinten', t==='doppelu'?3:1, 12);
      } else if(t==='inseln'){
        h += '<label class="cs-param"><span class="cs-lbl">Plätze je Insel</span><select class="input small" data-p="isl">'
          + [3,4,5,6,7,8].map(function(v){ return '<option'+(P.isl===v?' selected':'')+'>'+v+'</option>'; }).join('')+'</select></label>';
      } else if(t==='konferenz' || t==='bankett'){
        h += num('blocks','Blöcke',1,t==='bankett'?4:6);
      }
      return h;
    }

    function render(){
      if(!S.view) relayout();
      renderSvg(svg, S.tables, S.occ, S.view, {
        teacher:S.teacher, labels:labels, emptyText:'frei',
        selTable: S.mode==='arrange' ? S.sel : null,
        seatClass: function(id, s){
          if(S.mode==='assign' && id && S.pick===id) return 'cs-picked';
          if(S.mode==='arrange' && S.sel===s.ti && S.tables[s.ti].type==='chair') return 'cs-picked';
          return '';
        }
      });
      svg.classList.toggle('cs-arrange', S.mode==='arrange');
      // Werkzeugleiste
      $('[data-role="tpl"]').innerHTML = tplList.map(function(t){
        return '<button type="button" class="btn small'+(t[0]===S.tpl?'':' secondary')+'" data-tpl="'+t[0]+'" title="'+esc(t[2]||'')+'">'+esc(t[1])+'</button>';
      }).join('');
      var params = $('[data-role="params"]'), ph = paramsHtml();
      if(params.getAttribute('data-for')!==String(S.tpl) || !ph){ params.innerHTML = ph; params.setAttribute('data-for', String(S.tpl)); }
      params.hidden = !ph;
      root.querySelectorAll('[data-view]').forEach(function(b){
        var on = (b.getAttribute('data-view')==='teacher')===S.teacher;
        b.className = 'btn small'+(on?'':' secondary');
      });
      var seats = allSeats(S.tables).length, placed = n - unplaced().length;
      var stat = seats+' Plätze · '+placed+' von '+students.length+' platziert';
      if(seats < students.length) stat += ' · <b class="cs-warn">'+(students.length-seats)+' Plätze fehlen</b>';
      $('[data-role="stat"]').innerHTML = stat;
      var meta = tplList.filter(function(t){ return t[0]===S.tpl; })[0];
      $('[data-role="note"]').textContent = meta ? (meta[2]||'') : 'Eigene Anordnung. Eine Vorlage ersetzt die Tische, die Reihenfolge der Platzbelegung bleibt dabei erhalten.';
      root.querySelectorAll('.cs-tab').forEach(function(b){ b.setAttribute('aria-selected', String(b.getAttribute('data-mode')===S.mode)); });
      renderPanel();
    }

    function renderPanel(){
      var p = $('[data-role="panel"]'), h = '';
      if(S.mode==='arrange'){
        var t = S.sel!==null ? S.tables[S.sel] : null;
        h += '<div class="cs-h">Ausgewählt</div>';
        if(t){
          h += '<div class="cs-selbox"><b>'+esc(getType(t.type).label)+'</b> · Drehung '+(((t.rot%360)+360)%360)+'°</div>'
             + '<div class="cs-btnrow"><button type="button" class="btn small secondary" data-act="rot" data-v="-15">↺ 15°</button><button type="button" class="btn small secondary" data-act="rot" data-v="15">↻ 15°</button><button type="button" class="btn small secondary" data-act="rot" data-v="90">↻ 90°</button></div>'
             + (t.type==='board' ? '<div class="muted small">Die Tafel lässt sich verschieben und drehen, aber nicht entfernen.</div>'
                : '<div class="cs-btnrow"><button type="button" class="btn small secondary" data-act="dup">Duplizieren</button><button type="button" class="btn small danger" data-act="del">Entfernen</button></div>');
        } else {
          h += '<div class="muted small">Tisch, Lehrertisch oder Tafel antippen, um ihn zu drehen, zu duplizieren oder zu entfernen. Ziehen verschiebt ihn, Pfeiltasten verschieben in kleinen Schritten.</div>';
        }
        h += '<div class="cs-h">Hinzufügen</div><div class="cs-addgrid">'
           + ['t1','t2','t3','i4','i5','i6','chair','lt'].map(function(k){ return '<button type="button" class="btn small secondary" data-act="add" data-v="'+k+'">+ '+esc(getType(k).label)+'</button>'; }).join('')
           + '</div><div class="muted small">Belegte Plätze wandern beim Verschieben und Drehen mit.</div>';
      } else {
        var u = unplaced(), pickName = S.pick ? fullName[S.pick] : null, pickSeated = S.pick && !u.some(function(s){ return s.id===S.pick; });
        h += '<div class="cs-h">Noch ohne Platz ('+u.length+')</div>';
        h += u.length ? '<div class="cs-names">'+u.map(function(s){ return '<button type="button" class="btn small '+(S.pick===s.id?'':'secondary')+'" data-act="pick" data-v="'+s.id+'">'+esc(s.last+', '+s.first)+'</button>'; }).join('')+'</div>'
                      : '<div class="muted small">Alle Schüler:innen haben einen Platz.</div>';
        h += '<div class="muted small">'+(pickName ? '<b>'+esc(pickName)+'</b> ist ausgewählt. Jetzt einen Platz antippen.' : 'Name antippen, dann Platz antippen. Ein besetzter Platz lässt sich ebenfalls antippen und umsetzen; ist das Ziel belegt, tauschen die beiden.')+'</div>';
        if(pickSeated) h += '<div class="cs-btnrow"><button type="button" class="btn small danger" data-act="free">Platz von '+esc(labels[S.pick]||'')+' freigeben</button></div>';
        h += '<div class="cs-btnrow"><button type="button" class="btn small" data-act="auto">Freie Plätze alphabetisch belegen</button><button type="button" class="btn small secondary" data-act="clear">Alle Plätze leeren</button></div>';
      }
      p.innerHTML = h;
    }

    /* Ereignisse */
    root.addEventListener('click', function(e){
      var b = e.target.closest('button'); if(!b || !root.contains(b)) return;
      if(b.hasAttribute('data-tpl')){
        var key = b.getAttribute('data-tpl');
        if(S.tpl===null && !window.confirm('Die aktuelle Anordnung wird durch die Vorlage ersetzt. Die Reihenfolge der Platzbelegung bleibt erhalten. Fortfahren?')) return;
        S.tpl = key; S.P = paramDefaults(key, n); applyTemplate(true); return;
      }
      if(b.hasAttribute('data-view')){ S.teacher = b.getAttribute('data-view')==='teacher'; render(); return; }
      if(b.hasAttribute('data-mode')){ S.mode = b.getAttribute('data-mode'); S.pick = null; if(S.mode!=='arrange') S.sel = null; render(); return; }
      var a = b.getAttribute('data-act'); if(!a) return;
      var v = b.getAttribute('data-v'), t = S.sel!==null ? S.tables[S.sel] : null;
      if(a==='rot' && t){ t.rot += Number(v); S.tpl = null; markDirty(); relayout(); }
      else if(a==='dup' && t){ S.tables.push({type:t.type, x:t.x+40, y:t.y+40, rot:t.rot}); S.occ.push(getType(t.type).seats.map(function(){ return null; })); S.sel = S.tables.length-1; S.tpl = null; markDirty(); relayout(); }
      else if(a==='del' && t && t.type!=='board'){ S.tables.splice(S.sel,1); S.occ.splice(S.sel,1); S.sel = null; S.tpl = null; markDirty(); relayout(); }
      else if(a==='add'){ S.tables.push({type:v, x:Math.round(S.view.x+S.view.w/2+(Math.random()*60-30)), y:Math.round(S.view.y+S.view.h/2+(Math.random()*60-30)), rot:0}); S.occ.push(getType(v).seats.map(function(){ return null; })); S.sel = S.tables.length-1; S.tpl = null; markDirty(); relayout(); }
      else if(a==='pick'){ var id = parseInt(v,10); S.pick = S.pick===id ? null : id; }
      else if(a==='free'){ var so = seatOf(S.pick); if(so){ S.occ[so[0]][so[1]] = null; markDirty(); } S.pick = null; }
      else if(a==='auto'){ var u = unplaced().map(function(s){ return s.id; }); visualOrder(S.tables).forEach(function(s){ if(!S.occ[s.ti][s.si] && u.length){ S.occ[s.ti][s.si] = u.shift(); } }); S.pick = null; markDirty(); }
      else if(a==='clear'){ if(!window.confirm('Alle Platzzuweisungen dieses Sitzplans entfernen?')) return; S.occ = S.occ.map(function(r){ return r.map(function(){ return null; }); }); S.pick = null; markDirty(); }
      render();
    });
    root.addEventListener('change', function(e){
      var el = e.target, key = el.getAttribute && el.getAttribute('data-p'); if(!key) return;
      var lo = parseInt(el.min||'1',10)||1, hi = parseInt(el.max||'12',10)||12;
      S.P[key] = clamp(parseInt(el.value,10)||lo, el.tagName==='SELECT'?1:lo, el.tagName==='SELECT'?12:hi);
      el.value = S.P[key];
      applyTemplate(true);
    });

    function toSvg(e){ var pt = svg.createSVGPoint(); pt.x = e.clientX; pt.y = e.clientY; return pt.matrixTransform(svg.getScreenCTM().inverse()); }
    svg.addEventListener('pointerdown', function(e){
      var el = e.target.closest('[data-t]');
      if(S.mode==='arrange'){
        if(!el){ S.sel = null; render(); return; }
        var ti = parseInt(el.getAttribute('data-t'),10), t = S.tables[ti], p = toSvg(e);
        S.sel = ti; S.drag = {t:t, sx:p.x, sy:p.y, ox:t.x, oy:t.y, moved:false};
        try{ svg.setPointerCapture(e.pointerId); }catch(err){}
        e.preventDefault(); render(); return;
      }
      if(!el || !el.hasAttribute('data-s')) return;
      var sti = parseInt(el.getAttribute('data-t'),10), ssi = parseInt(el.getAttribute('data-s'),10), occ = S.occ[sti][ssi];
      if(S.pick){
        if(occ===S.pick){ S.pick = null; render(); return; }
        var from = seatOf(S.pick);
        if(from) S.occ[from[0]][from[1]] = occ || null;
        S.occ[sti][ssi] = S.pick; S.pick = null; markDirty(); render(); return;
      }
      if(occ){ S.pick = occ; render(); }
    });
    svg.addEventListener('pointermove', function(e){
      if(!S.drag) return;
      var p = toSvg(e), d = S.drag, k = S.teacher?-1:1;
      var nx = Math.round((d.ox+k*(p.x-d.sx))/5)*5, ny = Math.round((d.oy+k*(p.y-d.sy))/5)*5;
      if(nx!==d.t.x || ny!==d.t.y){ d.t.x = nx; d.t.y = ny; d.moved = true; render(); }
    });
    function endDrag(){ if(!S.drag) return; if(S.drag.moved){ S.tpl = null; markDirty(); } S.drag = null; relayout(); render(); }
    svg.addEventListener('pointerup', endDrag);
    svg.addEventListener('pointercancel', endDrag);
    root.addEventListener('keydown', function(e){
      if(S.mode!=='arrange' || S.sel===null) return;
      if(/INPUT|SELECT|TEXTAREA/.test((document.activeElement||{}).tagName||'')) return;
      var t = S.tables[S.sel], k = S.teacher?-1:1;
      var m = {ArrowLeft:[-5,0], ArrowRight:[5,0], ArrowUp:[0,-5], ArrowDown:[0,5]}[e.key];
      if(m){ t.x += k*m[0]; t.y += k*m[1]; S.tpl = null; markDirty(); e.preventDefault(); render(); }
      else if(e.key==='r' || e.key==='R'){ t.rot += e.shiftKey?-15:15; S.tpl = null; markDirty(); relayout(); render(); }
    });
    svg.setAttribute('tabindex','0');

    /* Speichern über das umgebende Formular */
    function serialize(){
      var layout = {version:1, tables:S.tables.map(function(t){ return {type:t.type, x:Math.round(t.x), y:Math.round(t.y), rot:((Math.round(t.rot)%360)+360)%360}; })};
      var seats = [];
      S.occ.forEach(function(row, ti){ row.forEach(function(id, si){ if(id) seats.push([id, ti+1, si+1]); }); });
      return {layout:layout, seats:seats};
    }
    if(cfg.form){
      cfg.form.addEventListener('submit', function(){
        var data = serialize();
        if(cfg.layoutInput) cfg.layoutInput.value = JSON.stringify(data.layout);
        if(cfg.seatsInput) cfg.seatsInput.value = JSON.stringify(data.seats);
        S.dirty = false;
      });
    }
    window.addEventListener('beforeunload', function(e){ if(S.dirty){ e.preventDefault(); e.returnValue = ''; } });
    if(!hasLayout) S.dirty = false;

    relayout(); render();
    return {serialize:serialize, state:S};
  }

  window.CoolSeating = {mountView:mountView, mountEditor:mountEditor, buildTemplate:buildTemplate, getType:getType, shortLabels:shortLabels};
})();
