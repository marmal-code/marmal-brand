-- 1.5.0: sekce „Digitál a sítě“ (digital) se dělí na Ikony (ikony), Web (web) a Sociální sítě (site).
-- Pravidla jsou stejná jako legacy_digital_section() v helpers.php.
-- Texty sekce se rozdělí podle nadpisů ## při prvním načtení textů brandu (section_texts() v brands.php).

UPDATE items SET section = 'ikony'
WHERE section = 'digital' AND slug NOT LIKE 'ikona-webu%'
  AND (slug IN ('ikona', 'ikony') OR slug LIKE 'ikona-%' OR slug LIKE 'ikony-%' OR slug LIKE '%-ikona' OR slug LIKE '%-ikony' OR slug LIKE '%-ikona-%' OR slug LIKE '%-ikony-%');

UPDATE items SET section = 'site'
WHERE section = 'digital'
  AND (slug LIKE 'profilov%' OR slug LIKE 'facebook%' OR slug LIKE 'linkedin%' OR slug LIKE 'instagram%' OR slug LIKE 'youtube%'
    OR slug LIKE 'tiktok%' OR slug LIKE 'sablona-prispevku%' OR slug LIKE 'sablona-stories%' OR slug LIKE 'prispevek%'
    OR slug LIKE 'stories%' OR slug LIKE 'avatar%' OR slug LIKE 'titulni-fotka%');

UPDATE items SET section = 'web' WHERE section = 'digital';

UPDATE template_items SET section = 'site'
WHERE section = 'digital'
  AND (slug LIKE 'profilov%' OR slug LIKE 'facebook%' OR slug LIKE 'linkedin%' OR slug LIKE 'instagram%' OR slug LIKE 'sablona-prispevku%' OR slug LIKE 'sablona-stories%');

UPDATE template_items SET section = 'ikony'
WHERE section = 'digital' AND slug NOT LIKE 'ikona-webu%' AND (slug LIKE 'ikona-%' OR slug LIKE 'ikony-%' OR slug LIKE '%-ikony');

UPDATE template_items SET section = 'web' WHERE section = 'digital';

-- Kukátko do přírody: maskot žabák a ilustrace ročních období se při importu dostaly do Pravidel použití, patří do Webu.
UPDATE items i JOIN brands b ON b.id = i.brand_id SET i.section = 'web', i.sort = i.sort + 1000
WHERE b.slug LIKE 'kukatko-do-prirody%' AND i.section = 'pravidla' AND (i.slug LIKE 'zabak-%' OR i.slug LIKE 'ilustrace-%');
