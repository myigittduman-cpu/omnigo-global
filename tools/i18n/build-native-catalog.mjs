import fs from 'node:fs/promises';
import {catalog} from '../../public/locales/catalog.js';
// The same reviewed messages are bundled for native/offline UI in both products.
for(const file of ['android/app/src/main/assets/native-translations.json','ios/Resources/native-translations.json','desktop/src/native-translations.json'])
 await fs.writeFile(file,JSON.stringify(catalog));
console.log('Android, iOS ve Windows yerel dil katalogları hazır.');
