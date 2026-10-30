import re, sys, os, glob
roots = sys.argv[1:]
files = []
for r in roots:
    files += [f for f in glob.glob(r + '/**/*.php', recursive=True) if '/vendor/' not in f]
classes = {}   # name -> set(methods), consts
consts = {}
funcs = set()
src = {}
for f in files:
    s = open(f, encoding='utf-8').read()
    src[f] = s
    # split by class
    for m in re.finditer(r'^(?:final\s+|abstract\s+)?class\s+(\w+)', s, re.M):
        name = m.group(1)
        start = m.end()
        nxt = re.search(r'^(?:final\s+|abstract\s+)?class\s+\w+', s[start:], re.M)
        body = s[start: start + nxt.start()] if nxt else s[start:]
        classes.setdefault(name, set()).update(re.findall(r'function\s+(\w+)\s*\(', body))
        consts.setdefault(name, set()).update(re.findall(r'const\s+(\w+)\s*=', body))
    for fn in re.findall(r'^function\s+(\w+)\s*\(', s, re.M):
        funcs.add(fn)
problems = []
for f, s in src.items():
    # current class context per position
    spans = [(m.start(), m.group(1)) for m in re.finditer(r'^(?:final\s+|abstract\s+)?class\s+(\w+)', s, re.M)]
    def cls_at(pos):
        c = None
        for st, n in spans:
            if st <= pos: c = n
        return c
    for m in re.finditer(r'\b([A-Z][A-Za-z_]+)::(\w+)\s*(\()?', s):
        c, meth, call = m.group(1), m.group(2), m.group(3)
        if c in ('self', 'static', 'parent'): continue
        if c not in classes: continue
        if call:
            if meth not in classes[c]: problems.append(f'{f}: {c}::{meth}() missing')
        else:
            if meth not in consts[c] and meth != 'class': problems.append(f'{f}: {c}::{meth} const missing')
    for m in re.finditer(r'\bself::(\w+)\s*(\()?', s):
        c = cls_at(m.start()); meth, call = m.group(1), m.group(2)
        if not c: continue
        if call and meth not in classes[c]: problems.append(f'{f}: self::{meth}() missing in {c}')
        if not call and meth not in consts[c] and not meth.startswith('$'): problems.append(f'{f}: self::{meth} const missing in {c}')
    for m in re.finditer(r"array\(\s*'([A-Za-z_\\\\]+)'\s*,\s*'(\w+)'\s*\)", s):
        c = m.group(1).split('\\')[-1]; meth = m.group(2)
        if c in classes and meth not in classes[c]: problems.append(f'{f}: callback {c}::{meth} missing')
    for m in re.finditer(r"array\(\s*__CLASS__\s*,\s*'(\w+)'\s*\)", s):
        c = cls_at(m.start())
        if c and m.group(1) not in classes[c]: problems.append(f'{f}: callback __CLASS__::{m.group(1)} missing in {c}')
    for m in re.finditer(r"\b(nhm_\w+|nh_hub_\w+)\s*\(", s):
        fn = m.group(1)
        if fn in funcs: continue
        # skip hook names inside quotes
        before = s[max(0, m.start()-1):m.start()]
        if before in ("'", '"'): continue
        problems.append(f'{f}: function {fn}() not defined')
print('\n'.join(sorted(set(problems))) or 'xref OK: %d classes, %d files' % (len(classes), len(files)))
