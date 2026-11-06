const fs = require('fs');
fs.mkdirSync('css', { recursive: true });
fs.writeFileSync('css/built.css', '/* built */\n');
