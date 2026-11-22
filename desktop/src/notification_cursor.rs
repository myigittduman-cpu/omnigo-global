// A cursor may pass only notifications which were displayed successfully.
pub fn progress(after:u64,feed_cursor:u64,ids:&[u64],delivered:usize)->Option<u64>{
    if feed_cursor<after||delivered>ids.len(){return None;}
    let mut previous=after;
    for id in ids{if *id<=previous||*id>feed_cursor{return None;}previous=*id;}
    Some(if delivered==ids.len(){feed_cursor}else if delivered==0{after}else{ids[delivered-1]})
}

pub fn deliver_batch<F,C>(after:u64,feed_cursor:u64,ids:&[u64],mut deliver:F,mut checkpoint:C)->bool
where F:FnMut(usize)->bool,C:FnMut(u64)->bool{
    if progress(after,feed_cursor,ids,0).is_none(){return false;}
    if ids.is_empty(){return checkpoint(feed_cursor);}
    for index in 0..ids.len(){
        if !deliver(index)||!checkpoint(progress(after,feed_cursor,ids,index+1).unwrap()){return false;}
    }
    true
}

#[cfg(test)]
mod tests{
    use super::progress;
    #[test]fn failed_first_item_keeps_the_previous_cursor(){assert_eq!(progress(10,25,&[12,18,24],0),Some(10));}
    #[test]fn failed_middle_item_retries_only_after_the_last_success(){assert_eq!(progress(10,25,&[12,18,24],1),Some(12));}
    #[test]fn completed_batch_advances_over_silent_source_events(){assert_eq!(progress(10,25,&[12,18,24],3),Some(25));assert_eq!(progress(10,25,&[],0),Some(25));}
    #[test]fn malformed_or_regressing_batch_cannot_change_the_cursor(){for ids in [vec![9],vec![12,12],vec![18,12],vec![26]]{assert_eq!(progress(10,25,&ids,0),None);}assert_eq!(progress(10,9,&[],0),None);assert_eq!(progress(10,25,&[12],2),None);}
    #[test]fn delivery_failure_retries_remaining_notifications_without_losing_or_replaying_success(){
        let mut shown=vec![];let mut cursor=10;
        assert!(!super::deliver_batch(cursor,25,&[12,18,24],|index|{if index==1{return false;}shown.push([12,18,24][index]);true},|next|{cursor=next;true}));
        assert_eq!(shown,vec![12]);assert_eq!(cursor,12);
        assert!(super::deliver_batch(cursor,25,&[18,24],|index|{shown.push([18,24][index]);true},|next|{cursor=next;true}));
        assert_eq!(shown,vec![12,18,24]);assert_eq!(cursor,25);
    }
    #[test]fn revoked_binding_or_failed_disk_checkpoint_stops_the_batch(){
        let mut attempts=0;assert!(!super::deliver_batch(10,25,&[12,18],|_|{attempts+=1;true},|_|false));assert_eq!(attempts,1);
        assert!(!super::deliver_batch(10,25,&[12,18],|_|false,|_|panic!("Başarısız bildirim işaretlenmemeli")));
    }
    #[test]fn invalid_batch_is_rejected_before_showing_or_saving_any_item(){
        assert!(!super::deliver_batch(10,25,&[12,11],|_|panic!("Geçersiz seri gösterilmemeli"),|_|panic!("Geçersiz seri kaydedilmemeli")));
    }
}
