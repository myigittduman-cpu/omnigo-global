import {getChannel} from '../channels.mjs';
export function storeChannel(name){return {...getChannel(name),version:'1.0.1',versionCode:2,targetApi:36};}
export function javaConfiguration(channel,{directUpdates=false}={}){
 const strings={HOST:channel.host,ORIGIN:channel.origin,TITLE:channel.title,NOTIFICATION_TITLE:channel.notificationTitle||channel.title,VERSION:channel.version,UPDATE_PATH:directUpdates?`/updates/android/${channel.name}/latest.json`:'',FILENAME_PREFIX:channel.filenamePrefix};
 return 'package com.omnigoglobal.client;\nfinal class PortalConfiguration {\n'+Object.entries(strings).map(([key,value])=>` static final String ${key} = ${JSON.stringify(value)};`).join('\n')+`\n static final int VERSION_CODE = ${channel.versionCode};\n static final boolean ALLOW_DIRECT_UPDATES = ${directUpdates};\n}\n`;
}
