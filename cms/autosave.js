'use strict';
// One request at a time. A response acknowledges its snapshot, never newer edits.
(function(root){
 function create({snapshot,save,acknowledge,notify,delay=1500,setTimer=setTimeout,clearTimer=clearTimeout}){
  let timer=null,pending=null,paused=false;
  function cancel(){if(timer!==null)clearTimer(timer);timer=null;}
  function schedule(){cancel();if(!paused)timer=setTimer(()=>{timer=null;void flush();},delay);}
  function flush(){
   cancel();if(pending)return pending;if(paused)return Promise.resolve(false);
   const sent=snapshot();if(!sent)return Promise.resolve(true);
   notify('saving');
   pending=Promise.resolve().then(()=>save(sent)).then(result=>{
    acknowledge(result,sent);notify('saved');return true;
   }).catch(error=>{paused=true;notify('error',error);return false;}).finally(()=>{
    pending=null;if(!paused&&snapshot())schedule();
   });return pending;
  }
  // Manual writes/site changes wait for the active request and cancel its successor.
  async function settle(){cancel();if(pending)await pending;cancel();}
  return {schedule,flush,settle,reset(){cancel();paused=false;}};
 }
 if(typeof module==='object'&&module.exports)module.exports={create};
 else root.CMSAutosave={create};
})(globalThis);
