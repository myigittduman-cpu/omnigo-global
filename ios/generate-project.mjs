import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
const root=import.meta.dirname,objects=new Map();
const id=key=>crypto.createHash('sha256').update(key).digest('hex').slice(0,24).toUpperCase();
const quote=value=>JSON.stringify(String(value));
const list=values=>'('+values.join(', ')+(values.length?',':'')+')';
const add=(key,value)=>{const ref=id(key);objects.set(ref,value);return ref;};
const settings=values=>'{ '+Object.entries(values).map(([key,value])=>(key.includes('[')?quote(key):key)+' = '+quote(value)+';').join(' ')+' }';
function configs(key,values){
 const configurations=['Debug','Release'].map(name=>add(key+name,`isa = XCBuildConfiguration; name = ${name}; buildSettings = ${settings({...values,APNS_ENVIRONMENT:name==='Debug'?'development':'production',PUSH_ENVIRONMENT:name==='Debug'?'sandbox':'production',SWIFT_OPTIMIZATION_LEVEL:name==='Debug'?'-Onone':'-O',DEBUG_INFORMATION_FORMAT:name==='Debug'?'dwarf':'dwarf-with-dsym',ENABLE_TESTABILITY:name==='Debug'?'YES':'NO'})};`));
 return add(key+'list',`isa = XCConfigurationList; buildConfigurations = ${list(configurations)}; defaultConfigurationIsVisible = 0; defaultConfigurationName = Release;`);
}
const sourceFiles=(await fs.readdir(path.join(root,'Sources'))).filter(file=>file.endsWith('.swift')).sort();
const sourceRefs=sourceFiles.map(file=>add('source'+file,`isa = PBXFileReference; lastKnownFileType = sourcecode.swift; path = ${quote('Sources/'+file)}; sourceTree = "<group>";`));
const testRef=add('testSource','isa = PBXFileReference; lastKnownFileType = sourcecode.swift; path = "Tests/PortalTests.swift"; sourceTree = "<group>";');
const resourceRefs=[['Resources/PrivacyInfo.xcprivacy','text.xml'],['Resources/portal-bridge.js','sourcecode.javascript'],['Resources/native-translations.json','text.json'],['Resources/Assets.xcassets','folder.assetcatalog']].map(([file,type])=>add(file,`isa = PBXFileReference; lastKnownFileType = ${type}; path = ${quote(file)}; sourceTree = "<group>";`));
const infoRef=add('info','isa = PBXFileReference; lastKnownFileType = text.plist.xml; path = "Resources/Info.plist"; sourceTree = "<group>";');
const macInfoRef=add('macInfo','isa = PBXFileReference; lastKnownFileType = text.plist.xml; path = "Resources/MacInfo.plist"; sourceTree = "<group>";');
const products=[],targets=[];
const common={SDKROOT:'iphoneos',IPHONEOS_DEPLOYMENT_TARGET:'16.0',SWIFT_VERSION:'5.0',CLANG_ENABLE_MODULES:'YES',CODE_SIGN_STYLE:'Automatic',TARGETED_DEVICE_FAMILY:'1,2',SUPPORTED_PLATFORMS:'iphoneos iphonesimulator',SUPPORTS_MACCATALYST:'NO',SUPPORTS_MAC_DESIGNED_FOR_IPHONE_IPAD:'NO'};
const catalyst={
 SUPPORTED_PLATFORMS:'iphoneos iphonesimulator macosx',
 SUPPORTS_MACCATALYST:'YES',
 MACOSX_DEPLOYMENT_TARGET:'13.0',
};
const macClient={
 ...catalyst,
 DERIVE_MACCATALYST_PRODUCT_BUNDLE_IDENTIFIER:'NO',
 'INFOPLIST_FILE[sdk=macosx*]':'Resources/MacInfo.plist',
 'CODE_SIGN_ENTITLEMENTS[sdk=macosx*]':'Resources/MacAppStore.entitlements',
 'ENABLE_HARDENED_RUNTIME[sdk=macosx*]':'YES',
 'ASSETCATALOG_COMPILER_APPICON_NAME[sdk=macosx*]':'AppIconMacClient',
 'APP_DISPLAY_NAME[sdk=macosx*]':'OG İş Ortağım',
};
for(const role of ['client','admin']){
 const name=role==='admin'?'OmnigoAdmin':'OmnigoClient',testName=name+'Tests';
 const appProduct=add(name+'product',`isa = PBXFileReference; explicitFileType = wrapper.application; path = ${name}.app; sourceTree = BUILT_PRODUCTS_DIR;`);
 const testProduct=add(testName+'product',`isa = PBXFileReference; explicitFileType = wrapper.cfbundle; path = ${testName}.xctest; sourceTree = BUILT_PRODUCTS_DIR;`);
 products.push(appProduct,testProduct);
 const phase=(key,type,refs)=>add(key,`isa = ${type}; buildActionMask = 2147483647; files = ${list(refs.map(ref=>add(key+ref,`isa = PBXBuildFile; fileRef = ${ref};`)))}; runOnlyForDeploymentPostprocessing = 0;`);
 const appSources=phase(name+'sources','PBXSourcesBuildPhase',sourceRefs);
 const resources=phase(name+'resources','PBXResourcesBuildPhase',resourceRefs);
 const frameworks=phase(name+'frameworks','PBXFrameworksBuildPhase',[]);
 const flags=role==='admin'?'$(inherited) ADMIN_APP':'$(inherited)';
 const appConfigs=configs(name,{...common,PRODUCT_NAME:name,PRODUCT_BUNDLE_IDENTIFIER:'com.omnigoglobal.'+role,INFOPLIST_FILE:'Resources/Info.plist',CODE_SIGN_ENTITLEMENTS:'Resources/Push.entitlements',APP_DISPLAY_NAME:role==='admin'?'OG Yönetici Portalı':'OG Müşteri Portalı',MARKETING_VERSION:'1.0.3',CURRENT_PROJECT_VERSION:'4',ASSETCATALOG_COMPILER_APPICON_NAME:role==='admin'?'AppIconAdmin':'AppIconClient',SWIFT_ACTIVE_COMPILATION_CONDITIONS:flags,LD_RUNPATH_SEARCH_PATHS:'$(inherited) @executable_path/Frameworks',...(role==='client'?macClient:{})});
 const target=add(name,`isa = PBXNativeTarget; buildConfigurationList = ${appConfigs}; buildPhases = ${list([appSources,frameworks,resources])}; buildRules = (); dependencies = (); name = ${name}; productName = ${name}; productReference = ${appProduct}; productType = "com.apple.product-type.application";`);
 const proxy=add(testName+'proxy',`isa = PBXContainerItemProxy; containerPortal = ${id('project')}; proxyType = 1; remoteGlobalIDString = ${target}; remoteInfo = ${name};`);
 const dependency=add(testName+'dependency',`isa = PBXTargetDependency; target = ${target}; targetProxy = ${proxy};`);
 const testSources=phase(testName+'sources','PBXSourcesBuildPhase',[testRef]);
 const testConfigs=configs(testName,{...common,PRODUCT_NAME:testName,PRODUCT_BUNDLE_IDENTIFIER:'com.omnigoglobal.'+role+'.tests',GENERATE_INFOPLIST_FILE:'YES',TEST_HOST:'$(BUILT_PRODUCTS_DIR)/'+name+'.app/$(BUNDLE_EXECUTABLE_FOLDER_PATH)/'+name,BUNDLE_LOADER:'$(TEST_HOST)',SWIFT_ACTIVE_COMPILATION_CONDITIONS:flags,LD_RUNPATH_SEARCH_PATHS:'$(inherited) @executable_path/Frameworks @loader_path/Frameworks',...(role==='client'?{...catalyst,'TEST_HOST[sdk=macosx*]':'$(BUILT_PRODUCTS_DIR)/'+name+'.app/Contents/MacOS/'+name}:{})});
 const testTarget=add(testName,`isa = PBXNativeTarget; buildConfigurationList = ${testConfigs}; buildPhases = ${list([testSources])}; buildRules = (); dependencies = ${list([dependency])}; name = ${testName}; productName = ${testName}; productReference = ${testProduct}; productType = "com.apple.product-type.bundle.unit-test";`);
 targets.push(target,testTarget);
 const buildRef=(target,name,product)=>`<BuildableReference BuildableIdentifier="primary" BlueprintIdentifier="${target}" BuildableName="${product}" BlueprintName="${name}" ReferencedContainer="container:Omnigo.xcodeproj"/>`;
 const ref=buildRef(target,name,name+'.app'),test=buildRef(testTarget,testName,testName+'.xctest');
 const scheme=`<?xml version="1.0" encoding="UTF-8"?>
<Scheme LastUpgradeVersion="2600" version="1.3">
 <BuildAction parallelizeBuildables="YES" buildImplicitDependencies="YES"><BuildActionEntries><BuildActionEntry buildForTesting="YES" buildForRunning="YES" buildForProfiling="YES" buildForArchiving="YES" buildForAnalyzing="YES">${ref}</BuildActionEntry></BuildActionEntries></BuildAction>
 <TestAction buildConfiguration="Debug" selectedDebuggerIdentifier="Xcode.DebuggerFoundation.Debugger.LLDB" selectedLauncherIdentifier="Xcode.IDEFoundation.Launcher.LLDB" shouldUseLaunchSchemeArgsEnv="YES"><Testables><TestableReference skipped="NO">${test}</TestableReference></Testables></TestAction>
 <LaunchAction buildConfiguration="Debug" selectedDebuggerIdentifier="Xcode.DebuggerFoundation.Debugger.LLDB" selectedLauncherIdentifier="Xcode.IDEFoundation.Launcher.LLDB" launchStyle="0" useCustomWorkingDirectory="NO" ignoresPersistentStateOnLaunch="NO" debugDocumentVersioning="YES" allowLocationSimulation="YES"><BuildableProductRunnable runnableDebuggingMode="0">${ref}</BuildableProductRunnable></LaunchAction>
 <ProfileAction buildConfiguration="Release" shouldUseLaunchSchemeArgsEnv="YES" savedToolIdentifier="" useCustomWorkingDirectory="NO" debugDocumentVersioning="YES"><BuildableProductRunnable runnableDebuggingMode="0">${ref}</BuildableProductRunnable></ProfileAction>
 <AnalyzeAction buildConfiguration="Debug"/><ArchiveAction buildConfiguration="Release" revealArchiveInOrganizer="YES"/>
</Scheme>`;
 const schemes=path.join(root,'Omnigo.xcodeproj/xcshareddata/xcschemes');await fs.mkdir(schemes,{recursive:true});await fs.writeFile(path.join(schemes,name+'.xcscheme'),scheme);
}
const productGroup=add('products',`isa = PBXGroup; children = ${list(products)}; name = Products; sourceTree = "<group>";`);
const mainGroup=add('main',`isa = PBXGroup; children = ${list([...sourceRefs,testRef,...resourceRefs,infoRef,macInfoRef,productGroup])}; sourceTree = "<group>";`);
const projectConfigs=configs('project',{ALWAYS_SEARCH_USER_PATHS:'NO',CLANG_ENABLE_OBJC_ARC:'YES',CLANG_WARN_DOCUMENTATION_COMMENTS:'YES',GCC_C_LANGUAGE_STANDARD:'gnu17',SWIFT_STRICT_CONCURRENCY:'targeted'});
add('project',`isa = PBXProject; attributes = { BuildIndependentTargetsInParallel = YES; LastUpgradeCheck = 2600; }; buildConfigurationList = ${projectConfigs}; compatibilityVersion = "Xcode 14.0"; developmentRegion = tr; hasScannedForEncodings = 0; knownRegions = (tr, en, de, Base,); mainGroup = ${mainGroup}; productRefGroup = ${productGroup}; projectDirPath = ""; projectRoot = ""; targets = ${list(targets)};`);
const sections=new Map();
for(const [ref,value] of objects){const type=value.match(/^isa = (\w+);/)[1];if(!sections.has(type))sections.set(type,[]);sections.get(type).push(ref+' = { '+value+' };');}
const body=[...sections].sort(([a],[b])=>a.localeCompare(b)).map(([type,entries])=>`/* Begin ${type} section */\n${entries.join('\n')}\n/* End ${type} section */`).join('\n\n');
await fs.writeFile(path.join(root,'Omnigo.xcodeproj/project.pbxproj'),'// !$*UTF8*$!\n{ archiveVersion = 1; classes = {}; objectVersion = 56; objects = {\n'+body+'\n}; rootObject = '+id('project')+'; }\n');
console.log('Müşteri ve yönetici Xcode projeleri oluşturuldu.');
