import test from 'node:test';
import assert from 'node:assert/strict';
import {selectSimulator} from '../ios/cloud-verify.mjs';

const phone = {name: 'iPhone 17', udid: '12345678-ABCD-1234-ABCD-123456789ABC', isAvailable: true};

test('bulut doğrulaması en yeni kullanılabilir iOS iPhone cihazını seçer', () => {
  const device = selectSimulator({devices: {
    'com.apple.CoreSimulator.SimRuntime.iOS-26-0': [phone],
    'com.apple.CoreSimulator.SimRuntime.iOS-26-2': [{...phone, name: 'iPhone 18'}],
    'com.apple.CoreSimulator.SimRuntime.tvOS-27-0': [phone],
    'com.apple.CoreSimulator.SimRuntime.iOS-27-0': [{...phone, isAvailable: false}, {...phone, name: 'iPad Pro'}]
  }});
  assert.equal(device.name, 'iPhone 18');
});

test('uygunsuz veya eksik simülatör envanteri başarılı sayılmaz', () => {
  for (const devices of [{}, {'com.apple.CoreSimulator.SimRuntime.iOS-18-0': [phone]},
    {'com.apple.CoreSimulator.SimRuntime.iOS-26-0': [{...phone, udid: ''}]},
    {'com.apple.CoreSimulator.SimRuntime.iOS-26-0': [{...phone, isAvailable: false}]}]) {
    assert.throws(() => selectSimulator({devices}), /simülatörü bulunamadı/);
  }
});
