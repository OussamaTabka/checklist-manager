import fs from 'fs';
import path from 'path';

const dirs = ['src'];
const exts = ['.vue', '.ts', '.js'];
let totalFixes = 0;
const fixedFiles = [];

function fixContent(content) {
  let n = 0;

  // BUG 1 — bindings Vue où l'apostrophe OUVRANTE est devenue un guillemet :
  // :class="{ "is-invalid': x }"  ->  :class="{ 'is-invalid': x }"
  content = content.replace(/(\{\s*)"([a-zA-Z][\w-]*)':/g, (m, p1, p2) => { n++; return `${p1}'${p2}':`; });

  // BUG 2 — bindings Vue où l'apostrophe FERMANTE est devenue un guillemet :
  // :class="{ 'is-invalid": x }"  ->  :class="{ 'is-invalid': x }"
  content = content.replace(/(\{\s*)'([a-zA-Z][\w-]*)":/g, (m, p1, p2) => { n++; return `${p1}'${p2}':`; });

  // BUG 3 — guillemet inséré au milieu d'un mot (apostrophe française cassée) :
  // d"accéder -> d'accéder , l"échelle -> l'échelle , etc.
  content = content.replace(/([a-zA-ZéèêàâîôûçÉÈÀÇ])"([a-zA-ZéèêàâîôûçÉÈÀÇ])/g, (m, p1, p2) => { n++; return `${p1}'${p2}`; });

  return { content, n };
}

function walk(dir) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, e.name);
    if (e.isDirectory()) {
      if (e.name === 'node_modules' || e.name === 'dist') continue;
      walk(full);
    } else if (exts.includes(path.extname(e.name))) {
      const original = fs.readFileSync(full, 'utf8');
      const { content, n } = fixContent(original);
      if (n > 0) {
        fs.writeFileSync(full, content, 'utf8');
        fixedFiles.push(`${full}  (${n})`);
        totalFixes += n;
      }
    }
  }
}

for (const d of dirs) if (fs.existsSync(d)) walk(d);

console.log('=== RÉPARATION TERMINÉE ===');
console.log('Corrections totales :', totalFixes);
console.log('Fichiers modifiés :');
fixedFiles.forEach(f => console.log('  ' + f));
