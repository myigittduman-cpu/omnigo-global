/*! JsBarcode 3.12.1 – CODE128 encoder only. https://github.com/lindell/JsBarcode
Copyright (c) 2016 Johan Lindell (johan@lindell.me)

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/
class Barcode{
	constructor(data, options){
		this.data = data;
		this.text = options.text || data;
		this.options = options;
	}
}

// constants for internal usage
const SET_A = 0;
const SET_B = 1;
const SET_C = 2;

// Special characters
const SHIFT = 98;
const START_A = 103;
const START_B = 104;
const START_C = 105;
const MODULO = 103;
const STOP = 106;
const FNC1 = 207;

// Get set by start code
const SET_BY_CODE = {
	[START_A]: SET_A,
	[START_B]: SET_B,
	[START_C]: SET_C,
};

// Get next set by code
const SWAP = {
	101: SET_A,
	100: SET_B,
	99: SET_C,
};

const A_START_CHAR = String.fromCharCode(208); // START_A + 105
const B_START_CHAR = String.fromCharCode(209); // START_B + 105
const C_START_CHAR = String.fromCharCode(210); // START_C + 105

// 128A (Code Set A)
// ASCII characters 00 to 95 (0–9, A–Z and control codes), special characters, and FNC 1–4
const A_CHARS = "[\x00-\x5F\xC8-\xCF]";

// 128B (Code Set B)
// ASCII characters 32 to 127 (0–9, A–Z, a–z), special characters, and FNC 1–4
const B_CHARS = "[\x20-\x7F\xC8-\xCF]";

// 128C (Code Set C)
// 00–99 (encodes two digits with a single code point) and FNC1
const C_CHARS = "(\xCF*[0-9]{2}\xCF*)";

// CODE128 includes 107 symbols:
// 103 data symbols, 3 start symbols (A, B and C), and 1 stop symbol (the last one)
// Each symbol consist of three black bars (1) and three white spaces (0).
const BARS = [
	11011001100, 11001101100, 11001100110, 10010011000, 10010001100,
	10001001100, 10011001000, 10011000100, 10001100100, 11001001000,
	11001000100, 11000100100, 10110011100, 10011011100, 10011001110,
	10111001100, 10011101100, 10011100110, 11001110010, 11001011100,
	11001001110, 11011100100, 11001110100, 11101101110, 11101001100,
	11100101100, 11100100110, 11101100100, 11100110100, 11100110010,
	11011011000, 11011000110, 11000110110, 10100011000, 10001011000,
	10001000110, 10110001000, 10001101000, 10001100010, 11010001000,
	11000101000, 11000100010, 10110111000, 10110001110, 10001101110,
	10111011000, 10111000110, 10001110110, 11101110110, 11010001110,
	11000101110, 11011101000, 11011100010, 11011101110, 11101011000,
	11101000110, 11100010110, 11101101000, 11101100010, 11100011010,
	11101111010, 11001000010, 11110001010, 10100110000, 10100001100,
	10010110000, 10010000110, 10000101100, 10000100110, 10110010000,
	10110000100, 10011010000, 10011000010, 10000110100, 10000110010,
	11000010010, 11001010000, 11110111010, 11000010100, 10001111010,
	10100111100, 10010111100, 10010011110, 10111100100, 10011110100,
	10011110010, 11110100100, 11110010100, 11110010010, 11011011110,
	11011110110, 11110110110, 10101111000, 10100011110, 10001011110,
	10111101000, 10111100010, 11110101000, 11110100010, 10111011110,
	10111101110, 11101011110, 11110101110, 11010000100, 11010010000,
	11010011100, 1100011101011
];

// Match Set functions
const matchSetALength = (string) => string.match(new RegExp(`^${A_CHARS}*`))[0].length;
const matchSetBLength = (string) => string.match(new RegExp(`^${B_CHARS}*`))[0].length;
const matchSetC = (string) => string.match(new RegExp(`^${C_CHARS}*`))[0];

// CODE128A or CODE128B
function autoSelectFromAB(string, isA){
	const ranges = isA ? A_CHARS : B_CHARS;
	const untilC = string.match(new RegExp(`^(${ranges}+?)(([0-9]{2}){2,})([^0-9]|$)`));

	if (untilC) {
		return (
			untilC[1] +
			String.fromCharCode(204) +
			autoSelectFromC(string.substring(untilC[1].length))
		);
	}

	const chars = string.match(new RegExp(`^${ranges}+`))[0];

	if (chars.length === string.length) {
		return string;
	}

	return (
		chars +
		String.fromCharCode(isA ? 205 : 206) +
		autoSelectFromAB(string.substring(chars.length), !isA)
	);
}

// CODE128C
function autoSelectFromC(string) {
	const cMatch = matchSetC(string);
	const length = cMatch.length;

	if (length === string.length) {
		return string;
	}

	string = string.substring(length);

	// Select A/B depending on the longest match
	const isA = matchSetALength(string) >= matchSetBLength(string);
	return cMatch + String.fromCharCode(isA ? 206 : 205) + autoSelectFromAB(string, isA);
}

// Detect Code Set (A, B or C) and format the string
const autoSelectModes = (string) => {
	let newString;
	const cLength = matchSetC(string).length;

	// Select 128C if the string start with enough digits
	if (cLength >= 2) {
		newString = C_START_CHAR + autoSelectFromC(string);
	} else {
		// Select A/B depending on the longest match
		const isA = matchSetALength(string) > matchSetBLength(string);
		newString = (isA ? A_START_CHAR : B_START_CHAR) + autoSelectFromAB(string, isA);
	}

	return newString.replace(
		/[\xCD\xCE]([^])[\xCD\xCE]/, // Any sequence between 205 and 206 characters
		(match, char) => String.fromCharCode(203) + char
	);
};

// This is the master class,
// it does require the start code to be included in the string
class CODE128 extends Barcode {
	constructor(data, options) {
		super(data.substring(1), options);

		// Get array of ascii codes from data
		this.bytes = data.split('')
			.map(char => char.charCodeAt(0));
	}

	valid() {
		// ASCII value ranges 0-127, 200-211
		return /^[\x00-\x7F\xC8-\xD3]+$/.test(this.data);
	}

	// The public encoding function
	encode() {
		const bytes = this.bytes;
		// Remove the start code from the bytes and set its index
		const startIndex = bytes.shift() - 105;
		// Get start set by index
		const startSet = SET_BY_CODE[startIndex];

		if (startSet === undefined) {
			throw new RangeError('The encoding does not start with a start character.');
		}

		if (this.shouldEncodeAsEan128() === true) {
			bytes.unshift(FNC1);
		}

		// Start encode with the right type
		const encodingResult = CODE128.next(bytes, 1, startSet);

		return {
			text:
				this.text === this.data
					? this.text.replace(/[^\x20-\x7E]/g, '')
					: this.text,
			data:
				// Add the start bits
				CODE128.getBar(startIndex) +
				// Add the encoded bits
				encodingResult.result +
				// Add the checksum
				CODE128.getBar((encodingResult.checksum + startIndex) % MODULO) +
				// Add the end bits
				CODE128.getBar(STOP)
		};
	}

	// GS1-128/EAN-128
	shouldEncodeAsEan128() {
		let isEAN128 = this.options.ean128 || false;
		if (typeof isEAN128 === 'string') {
			isEAN128 = isEAN128.toLowerCase() === 'true';
		}
		return isEAN128;
	}

	// Get a bar symbol by index
	static getBar(index) {
		return BARS[index] ? BARS[index].toString() : '';
	}

	// Correct an index by a set and shift it from the bytes array
	static correctIndex(bytes, set) {
		if (set === SET_A) {
			const charCode = bytes.shift();
			return charCode < 32 ? charCode + 64 : charCode - 32;
		} else if (set === SET_B) {
			return bytes.shift() - 32;
		} else {
			return (bytes.shift() - 48) * 10 + bytes.shift() - 48;
		}
	}

	static next(bytes, pos, set) {
		if (!bytes.length) {
			return { result: '', checksum: 0 };
		}

		let nextCode, index;

		// Special characters
		if (bytes[0] >= 200){
			index = bytes.shift() - 105;
			const nextSet = SWAP[index];

			// Swap to other set
			if (nextSet !== undefined) {
				nextCode = CODE128.next(bytes, pos + 1, nextSet);
			}
			// Continue on current set but encode a special character
			else {
				// Shift
				if ((set === SET_A || set === SET_B) && index === SHIFT) {
					// Convert the next character so that is encoded correctly
					bytes[0] = (set === SET_A)
						? bytes[0] > 95 ? bytes[0] - 96 : bytes[0]
						: bytes[0] < 32 ? bytes[0] + 96 : bytes[0];
				}
				nextCode = CODE128.next(bytes, pos + 1, set);
			}
		}
		// Continue encoding
		else {
			index = CODE128.correctIndex(bytes, set);
			nextCode = CODE128.next(bytes, pos + 1, set);
		}

		// Get the correct binary encoding and calculate the weight
		const enc = CODE128.getBar(index);
		const weight = index * pos;

		return {
			result: enc + nextCode.result,
			checksum: weight + nextCode.checksum
		};
	}
}

class CODE128AUTO extends CODE128{
	constructor(data, options){
    // ASCII value ranges 0-127, 200-211
		if (/^[\x00-\x7F\xC8-\xD3]+$/.test(data)) {
			super(autoSelectModes(data), options);
		} else{
			super(data, options);
		}
	}
}

export default CODE128AUTO;
