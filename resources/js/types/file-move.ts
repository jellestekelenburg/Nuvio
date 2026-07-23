export type FileMovePayloadSelection =
    | {
          mode: 'ids';
          ids: number[];
      }
    | {
          mode: 'all';
          source_parent_id: number;
          excluded_ids: number[];
      };

export type FileMovePayload = {
    selection: FileMovePayloadSelection;
    target_parent_id: number | null;
};

export type RenamedFileMoveItem = {
    id: number;
    old_name: string;
    new_name: string;
};

export type FileMoveResult = {
    moved_count: number;
    renamed_count: number;
    source_parent_ids: number[];
    target_parent_id: number;
    renamed_items: RenamedFileMoveItem[];
};
