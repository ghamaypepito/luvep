// Framework chrome is not the subject of the component interaction tests.
const React=require('react');
exports.Head=()=>null;
exports.Link=props=>React.createElement('a',props,props.children);
exports.usePage=()=>global.__epTestPage || {};
exports.router={get page(){return global.__epTestPage;},on:(name,hook)=>{if(name!=='before')return ()=>{};global.__epBeforeHooks ||= [];global.__epBeforeHooks.push(hook);return ()=>{global.__epBeforeHooks=global.__epBeforeHooks.filter(item=>item!==hook);};}};
