// Respect reduced motion (turn off 3D/animations if requested)
const PREFERS_REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ================================
   Three.js — Clear 3D wave + soft orbs
   (visible depth, gentle motion, low distraction)
================================== */
(function waveBG(){
  if (PREFERS_REDUCED) return;
  const canvas = document.getElementById('fx');
  if (!canvas || !window.THREE) return;

  const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.setSize(window.innerWidth, window.innerHeight);
  renderer.setClearColor(0x090c13, 1);

  const scene = new THREE.Scene();
  scene.fog = new THREE.FogExp2(0x090c13, 0.014);

  const camera = new THREE.PerspectiveCamera(60, window.innerWidth / window.innerHeight, 0.1, 2000);
  camera.position.set(0, 22, 105);

  // Lights: add contrast so the surface reads as 3D
  const amb = new THREE.AmbientLight(0x9aa7ff, 0.35);
  const dir1 = new THREE.DirectionalLight(0x7a6cff, 1.1); dir1.position.set(-60, 80, 60);
  const dir2 = new THREE.DirectionalLight(0x60d0ff, 0.85); dir2.position.set(60, -30, -60);
  scene.add(amb, dir1, dir2);

  // Wave mesh — larger facets so the shape is legible
  const SEG = 120;
  const geo = new THREE.PlaneGeometry(220, 220, SEG, SEG);
  const mat = new THREE.MeshPhongMaterial({
    color: 0x0f1320,
    shininess: 38,
    specular: 0x4450aa,
    side: THREE.DoubleSide,
    flatShading: true
  });
  const mesh = new THREE.Mesh(geo, mat);
  mesh.rotation.x = -Math.PI/2.15;
  scene.add(mesh);

  // Sparse, faint orbs for depth (not cubes, not noisy)
  const orbs = new THREE.Group();
  const orbGeo = new THREE.SphereGeometry(0.9, 16, 16);
  for (let i=0;i<42;i++){
    const color = i % 2 ? 0x99ccff : 0xffffff;
    const orbMat = new THREE.MeshBasicMaterial({ color, transparent: true, opacity: 0.12, depthWrite:false });
    const orb = new THREE.Mesh(orbGeo, orbMat);
    orb.position.set((Math.random()-0.5)*220, Math.random()*42+8, (Math.random()-0.5)*220);
    const s = 0.7 + Math.random()*1.8;
    orb.scale.set(s, s, s);
    orbs.add(orb);
  }
  scene.add(orbs);

  // Resize handling
  function onResize(){
    const w = window.innerWidth, h = window.innerHeight;
    camera.aspect = w / h; camera.updateProjectionMatrix();
    renderer.setSize(w, h, false);
  }
  window.addEventListener('resize', onResize);

  // Mouse parallax
  let mx = 0, my = 0;
  window.addEventListener('mousemove', e=>{
    mx = (e.clientX / window.innerWidth) - 0.5;
    my = (e.clientY / window.innerHeight) - 0.5;
  }, { passive:true });

  // Animate
  let t = 0;
  const pos = geo.attributes.position;
  (function animate(){
    t += 0.015;

    // Stronger but smooth displacement so it's clearly 3D
    for(let i=0;i<pos.count;i++){
      const x = pos.getX(i), y = pos.getY(i);
      const z = Math.sin(x*0.16 + t) * 2.6 + Math.cos(y*0.20 + t*1.25) * 2.6;
      pos.setZ(i, z);
    }
    pos.needsUpdate = true;
    geo.computeVertexNormals();

    // Camera drift towards mouse
    camera.position.x += (mx*40 - camera.position.x) * 0.02;
    camera.position.y += (22 + my*18 - camera.position.y) * 0.02;
    camera.lookAt(0,0,0);

    // Orbs slow float
    orbs.children.forEach((o,i)=>{ o.position.y += Math.sin(t*0.35 + i) * 0.02; });

    renderer.render(scene, camera);
    requestAnimationFrame(animate);
  })();

  onResize();
})();

/* ================================
   Parallax glow layer
================================== */
(function(){
  const p = document.querySelector('.parallax');
  if(!p) return;
  window.addEventListener('scroll', () => {
    const speed = parseFloat(p.dataset.speed || '0.12');
    p.style.transform = `translate3d(0, ${window.scrollY * speed}px, 0)`;
  }, {passive:true});
})();

/* ================================
   Reveal-on-scroll + fluid dials
================================== */
(function(){
  const els = [...document.querySelectorAll('[data-reveal]')];
  const dials = [...document.querySelectorAll('.dial i')];

  if(!('IntersectionObserver' in window) || PREFERS_REDUCED){
    els.forEach(el => { el.style.opacity = 1; el.style.transform = 'none'; });
    dials.forEach(d => { d.style.width = getComputedStyle(d).getPropertyValue('--v'); });
    return;
  }
  const io = new IntersectionObserver(entries=>{
    entries.forEach(e=>{
      if(e.isIntersecting){
        e.target.classList.add('reveal-animate');
        e.target.style.opacity = 1;
        e.target.style.transform = 'translateY(0)';
        e.target.querySelectorAll?.('.dial i').forEach(i=>{
          i.style.width = getComputedStyle(i).getPropertyValue('--v');
        });
        io.unobserve(e.target);
      }
    });
  }, {threshold:.15});
  els.forEach(el=>io.observe(el));
})();

/* ================================
   Magnetic buttons
================================== */
(function(){
  if(PREFERS_REDUCED) return;
  document.querySelectorAll('.magnet').forEach(btn=>{
    const k = 18;
    btn.addEventListener('mousemove', e=>{
      const r = btn.getBoundingClientRect();
      btn.style.transform = `translate(${(e.clientX - (r.left+r.width/2))/k}px, ${(e.clientY - (r.top+r.height/2))/k}px)`;
    });
    btn.addEventListener('mouseleave', ()=> btn.style.transform='translate(0,0)');
  });
})();

/* ================================
   Subtle float + tilt on the hero stack
================================== */
(function(){
  if(PREFERS_REDUCED) return;
  const stack = document.querySelector('.hero-right .stack');
  if(!stack) return;

  let t = 0;
  function tick(){
    t += 0.016;
    stack.style.transform = `translateY(${Math.sin(t)*2.2}px)`;
    requestAnimationFrame(tick);
  }
  requestAnimationFrame(tick);

  const max = 2.6; // degrees
  function move(e){
    const r = stack.getBoundingClientRect();
    const dx = (e.clientX - (r.left + r.width/2)) / (r.width/2);
    const dy = (e.clientY - (r.top + r.height/2)) / (r.height/2);
    stack.style.transform += ` rotateX(${(-dy*max).toFixed(2)}deg) rotateY(${(dx*max).toFixed(2)}deg)`;
  }
  function leave(){ stack.style.transform = ''; }
  stack.addEventListener('mousemove', move);
  stack.addEventListener('mouseleave', leave);
})();
